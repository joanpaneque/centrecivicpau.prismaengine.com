<?php

use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('customers are created from the tpv with a code of their own and optional fiscal data', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(route('tpv.customers.store'), ['code' => 'EMP1', 'name' => 'Empresa Prova', 'taxId' => 'b-12.345.678'])
        ->assertCreated()
        ->assertJsonPath('customer.taxId', 'B12345678');

    $this->actingAs($admin)->postJson(route('tpv.customers.store'), ['code' => 'EMP1', 'name' => 'Una altra'])->assertUnprocessable();
    $this->actingAs($admin)->postJson(route('tpv.customers.store'), ['code' => 'JOAN', 'name' => 'Joan'])->assertCreated();

    $this->actingAs($admin)->getJson(route('tpv.customers.index', ['q' => 'emp']))
        ->assertOk()
        ->assertJsonCount(1, 'customers')
        ->assertJsonPath('customers.0.code', 'EMP1');
});

test('an order goes to a customer and its tickets follow it', function () {
    $admin = User::factory()->admin()->create();
    $customer = Customer::query()->create(['code' => 'C1', 'name' => 'Client u']);
    $ticket = issueTpvTicket($this, str_repeat('e', 32));
    $order = Order::query()->create(['uuid' => (string) Str::uuid(), 'status' => 'paid', 'opened_at' => now()]);
    $ticket->forceFill(['order_id' => $order->id])->save();
    $cookie = registerTpvDevice($this, $admin, 'tablet');

    $this->actingAs($admin)->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->postJson(route('tpv.push'), ['operations' => [tpvOperation('order.customer', ['orderUuid' => $order->uuid, 'customerId' => $customer->id], $admin->id)]])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'ok');

    expect($order->refresh()->customer_id)->toBe($customer->id)
        ->and($ticket->refresh()->customer_id)->toBe($customer->id);
});

test('the summary groups the pending tickets of a customer and its qr gives one invoice for all of them', function () {
    $admin = User::factory()->admin()->create();
    $customer = Customer::query()->create(['code' => 'EMP', 'name' => 'Empresa Prova SL', 'tax_id' => 'B12345678', 'address' => 'Carrer Major 1, 08001 Barcelona']);
    $first = issueTpvTicket($this, str_repeat('f', 32));
    $second = issueTpvTicket($this, str_repeat('g', 32));

    foreach ([$first, $second] as $ticket) {
        $this->actingAs($admin)->postJson(route('tpv.customers.attach', $customer), ['ticketUuid' => $ticket->uuid])->assertOk();
    }

    $this->actingAs($admin)->getJson(route('tpv.customers.show', $customer))->assertJsonCount(2, 'pending');

    $response = $this->actingAs($admin)->postJson(route('tpv.customers.statements.store', $customer), ['ticketUuids' => []])
        ->assertCreated()
        ->assertJsonPath('statement.total', 1000)
        ->assertJsonCount(2, 'statement.tickets');

    $statement = CustomerStatement::query()->findOrFail($response->json('statement.id'));

    expect($response->json('statement.invoiceUrl'))->toContain('/factura/resum/'.$statement->public_token);

    // Nothing pending any more, and a ticket in a summary cannot move to another customer.
    $this->actingAs($admin)->getJson(route('tpv.customers.show', $customer))->assertJsonCount(0, 'pending');
    $this->actingAs($admin)->postJson(route('tpv.customers.statements.store', $customer))->assertUnprocessable();
    $this->actingAs($admin)->postJson(route('tpv.customers.attach', $customer), ['ticketUuid' => $first->uuid])->assertUnprocessable();

    $this->get(route('invoice-request.statement', $statement->public_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ticket.count', 2)->where('customer.taxId', 'B12345678')->where('invoice', null));

    $this->post(route('invoice-request.statement.store', $statement->public_token), [
        'customer_name' => 'Empresa Prova SL',
        'customer_tax_id' => 'B12345678',
        'address' => 'Carrer Major 1, 08001 Barcelona',
    ])->assertRedirect(route('invoice-request.statement', $statement->public_token));

    $invoice = Invoice::query()->where('customer_statement_id', $statement->id)->firstOrFail();

    expect($invoice->ticket_id)->toBeNull()
        ->and(Invoice::query()->count())->toBe(1);

    $this->get(route('invoice-request.statement.pdf', $statement->public_token))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // The QR of each ticket now shows that invoice and does not issue another one.
    $this->get(route('invoice-request', $first->public_token))
        ->assertInertia(fn (Assert $page) => $page->where('invoice.fullNumber', $invoice->full_number));

    $this->post(route('invoice-request.store', $first->public_token), [
        'customer_name' => 'Altre', 'customer_tax_id' => 'B87654321', 'address' => 'C/ 2', 'postal_code' => '08002', 'city' => 'Barcelona',
    ])->assertSessionHasErrors('customer_name');

    expect(Invoice::query()->count())->toBe(1);
});

test('a delivery note closes the order unpaid and is charged later into a normal ticket', function () {
    $admin = User::factory()->admin()->create();
    $customer = Customer::query()->create(['code' => 'ALB', 'name' => 'Client albarans']);
    $cookie = registerTpvDevice($this, $admin, 'cashier');
    $client = fn () => $this->actingAs($admin)->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie);
    $order = Order::query()->create(['uuid' => (string) Str::uuid(), 'status' => 'open', 'opened_at' => now(), 'customer_id' => $customer->id]);
    $line = $order->lines()->create(['uuid' => (string) Str::uuid(), 'name' => ['ca' => 'Cafè', 'es' => 'Café'], 'quantity' => 2, 'unit_price' => 150, 'vat_rate' => 10]);

    $client()->postJson(route('tpv.push'), ['operations' => [tpvOperation('delivery_note.issue', [
        'note' => [
            'uuid' => (string) Str::uuid(),
            'orderUuid' => $order->uuid,
            'issuedAt' => now()->toIso8601String(),
            'surchargeRate' => 0,
            'total' => 300,
            'lines' => [['orderLineUuid' => $line->uuid, 'name' => ['ca' => 'Cafè', 'es' => 'Café'], 'quantity' => 2, 'unitPrice' => 150, 'vatRate' => 10, 'total' => 300]],
        ],
        'paidLines' => [['lineUuid' => $line->uuid, 'quantity' => 2]],
        'closeOrder' => true,
    ], $admin->id)]])->assertOk()->assertJsonPath('results.0.status', 'ok');

    $note = DeliveryNote::query()->firstOrFail();

    expect($note->customer_id)->toBe($customer->id)
        ->and($note->total)->toBe(300)
        ->and($note->ticket_id)->toBeNull()
        ->and($order->refresh()->status)->toBe('paid');

    $client()->getJson(route('tpv.customers.show', $customer))->assertJsonCount(1, 'notes');

    $collect = $client()->postJson(route('tpv.customers.notes.collect', $customer), ['noteUuids' => [$note->uuid]])->assertCreated();
    $collector = Order::query()->where('uuid', $collect->json('order.uuid'))->firstOrFail();

    expect($collect->json('order.collectsNotes'))->toBeTrue()
        ->and($collector->customer_id)->toBe($customer->id)
        ->and((int) $collector->lines->sum(fn ($l) => $l->unit_price * $l->quantity))->toBe(300);

    $sessionUuid = (string) Str::uuid();
    $client()->postJson(route('tpv.push'), ['operations' => [tpvOperation('cash.open', ['sessionUuid' => $sessionUuid], $admin->id)]])->assertOk();
    $series = $client()->getJson(route('tpv.bootstrap'))->json('cashier.series.code');

    $client()->postJson(route('tpv.push'), ['operations' => [tpvOperation('ticket.issue', [
        'ticket' => [
            'uuid' => (string) Str::uuid(),
            'seriesCode' => $series,
            'number' => 1,
            'orderUuid' => $collector->uuid,
            'cashSessionUuid' => $sessionUuid,
            'issuedAt' => now()->toIso8601String(),
            'surchargeRate' => 0,
            'total' => 300,
            'publicToken' => str_repeat('h', 32),
            'lines' => [['name' => ['ca' => 'Cafè', 'es' => 'Café'], 'quantity' => 2, 'unitPrice' => 150, 'vatRate' => 10, 'total' => 300]],
            'payments' => [['method' => 'cash', 'amount' => 300]],
        ],
        'paidLines' => $collector->lines->map(fn ($l) => ['lineUuid' => $l->uuid, 'quantity' => $l->quantity])->all(),
        'closeOrder' => true,
    ], $admin->id)]])->assertOk()->assertJsonPath('results.0.status', 'ok');

    expect($note->refresh()->ticket_id)->not->toBeNull()
        ->and($note->ticket->customer_id)->toBe($customer->id);

    $client()->getJson(route('tpv.customers.show', $customer))->assertJsonCount(0, 'notes')->assertJsonCount(1, 'pending');
});
