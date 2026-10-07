<?php

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use App\Services\TimeTracking\ClockQrService;
use Illuminate\Support\Str;

function orderSendPayload(string $orderUuid, int $price = 250): array
{
    return [
        'orderUuid' => $orderUuid,
        'tableId' => null,
        'guests' => 2,
        'label' => 'Barra',
        'openedAt' => now()->toIso8601String(),
        'sentAt' => now()->toIso8601String(),
        'lines' => [[
            'uuid' => (string) Str::uuid(),
            'productId' => null,
            'setMenuId' => null,
            'destinationId' => null,
            'name' => ['ca' => 'Cafè', 'es' => 'Café'],
            'quantity' => 2,
            'unitPrice' => $price,
            'vatRate' => 10,
            'modifiers' => [],
            'note' => null,
            'course' => null,
            'children' => [],
        ]],
        'kitchenTickets' => [],
    ];
}

test('guests cannot bootstrap the pos', function () {
    $this->getJson(route('tpv.bootstrap'))->assertUnauthorized();
});

test('bootstrap returns the full snapshot for a registered device', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->getJson(route('tpv.bootstrap'))
        ->assertOk()
        ->assertJsonPath('full', true)
        ->assertJsonPath('me.id', $user->id)
        ->assertJsonPath('device.type', 'tablet')
        ->assertJsonPath('clock', null)
        ->assertJsonStructure(['settings', 'staff', 'zones', 'tables', 'products', 'orders', 'cursor', 'serverTime']);
});

test('the cashier device also receives the clock-in QR so one screen can do both', function () {
    $admin = User::factory()->admin()->create();
    $cookie = registerTpvDevice($this, $admin, 'cashier');

    $clock = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->getJson(route('tpv.bootstrap'))
        ->assertOk()
        ->assertJsonPath('device.type', 'cashier')
        ->json('clock');

    expect($clock)->toHaveKeys(['secret', 'seconds', 'url'])
        ->and($clock['secret'])->toBeString()->not->toBeEmpty()
        ->and($clock['seconds'])->toBeGreaterThanOrEqual(30);
});

test('staff cannot register a cashier device', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('tpv.device'), ['name' => 'Caixa', 'type' => 'cashier'])->assertForbidden();
});

test('pushed orders are stored once even when the same operation is sent twice', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    $orderUuid = (string) Str::uuid();
    $operation = tpvOperation('order.send', orderSendPayload($orderUuid), $user->id);

    $first = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [$operation]])->assertOk();
    $second = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [$operation]])->assertOk();

    expect($first->json('results.0.status'))->toBe('ok')
        ->and($second->json('results.0.status'))->toBe('ok')
        ->and(Order::query()->where('uuid', $orderUuid)->count())->toBe(1)
        ->and(Order::query()->where('uuid', $orderUuid)->first()?->lines()->count())->toBe(1);

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->getJson(route('tpv.pull', ['since' => now()->subMinute()->toIso8601String()]))
        ->assertOk()
        ->assertJsonPath('full', false)
        ->assertJsonFragment(['uuid' => $orderUuid]);
});

test('unknown operations are rejected without breaking the batch', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);

    $response = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('nope.nope', []),
        tpvOperation('order.send', orderSendPayload((string) Str::uuid()), $user->id),
    ]])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected')
        ->and($response->json('results.0.reason'))->toBe('unknown_operation')
        ->and($response->json('results.1.status'))->toBe('ok');
});

test('kitchen users cannot create orders', function () {
    $cook = User::factory()->create(['role' => 'kitchen']);
    $cookie = registerTpvDevice($this, $cook, 'kds');

    $response = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->postJson(route('tpv.push'), ['operations' => [tpvOperation('order.send', orderSendPayload((string) Str::uuid()), $cook->id)]])
        ->assertOk();

    expect($response->json('results.0.reason'))->toBe('forbidden');
});

test('a cashier opens a session, issues a ticket and closes with a Z report', function () {
    $admin = User::factory()->admin()->create();
    $cookie = registerTpvDevice($this, $admin, 'cashier');
    $orderUuid = (string) Str::uuid();
    $sessionUuid = (string) Str::uuid();
    $ticketUuid = (string) Str::uuid();

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('order.send', orderSendPayload($orderUuid), $admin->id),
        tpvOperation('cash.open', ['sessionUuid' => $sessionUuid, 'openingFloat' => 10000], $admin->id),
    ]])->assertOk();

    $snapshot = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->getJson(route('tpv.bootstrap'))->assertOk();
    $series = $snapshot->json('cashier.series.code');
    $lineUuid = Order::query()->where('uuid', $orderUuid)->firstOrFail()->lines()->value('uuid');

    expect($series)->toBeString()->and($snapshot->json('cashier.session.uuid'))->toBe($sessionUuid);

    $issue = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('ticket.issue', [
            'ticket' => [
                'uuid' => $ticketUuid,
                'seriesCode' => $series,
                'number' => 1,
                'orderUuid' => $orderUuid,
                'cashSessionUuid' => $sessionUuid,
                'tableLabel' => 'Barra',
                'issuedAt' => now()->toIso8601String(),
                'surchargeRate' => 0,
                'total' => 500,
                'publicToken' => str_repeat('a', 32),
                'lines' => [['orderLineUuid' => $lineUuid, 'name' => ['ca' => 'Cafè', 'es' => 'Café'], 'quantity' => 2, 'unitPrice' => 250, 'vatRate' => 10, 'discountAmount' => 0, 'total' => 500]],
                'payments' => [['uuid' => (string) Str::uuid(), 'method' => 'cash', 'amount' => 500, 'tendered' => 1000, 'change' => 500]],
            ],
            'paidLines' => [['lineUuid' => $lineUuid, 'quantity' => 2]],
            'closeOrder' => true,
        ], $admin->id),
    ]])->assertOk();

    expect($issue->json('results.0.status'))->toBe('ok');

    $ticket = Ticket::query()->where('uuid', $ticketUuid)->firstOrFail();

    expect($ticket->full_number)->toBe($series.'-000001')
        ->and($ticket->total)->toBe(500)
        ->and($ticket->vat_breakdown[0]['base'])->toBe(455)
        ->and($ticket->vat_breakdown[0]['vat'])->toBe(45)
        ->and($ticket->payments()->count())->toBe(1)
        ->and(Order::query()->where('uuid', $orderUuid)->value('status'))->toBe('paid');

    $close = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('cash.close', ['sessionUuid' => $sessionUuid, 'cashCount' => ['5000' => 3]], $admin->id),
    ]])->assertOk();

    expect($close->json('results.0.result.summary.tickets'))->toBe(1)
        ->and($close->json('results.0.result.summary.expected_cash'))->toBe(10500)
        ->and($close->json('results.0.result.difference'))->toBe(4500)
        ->and($close->json('results.0.result.zNumber'))->toBe(1);
});

test('tickets can only be issued from cashier devices', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);

    $response = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->postJson(route('tpv.push'), ['operations' => [tpvOperation('cash.open', ['sessionUuid' => (string) Str::uuid()], $user->id)]])
        ->assertOk();

    expect($response->json('results.0.reason'))->toBe('cashier_device_required');
});

test('clocking in with the dynamic QR requires a valid code', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    $qr = app(ClockQrService::class);

    $bad = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('time.clock', ['type' => 'clock_in', 'occurredAt' => now()->toIso8601String(), 'source' => 'qr', 'qrCode' => '123.0000000000000000'], $user->id),
    ]])->assertOk();

    expect($bad->json('results.0.reason'))->toBe('invalid_clock_qr');

    $code = $qr->codeFor($qr->window(now()));
    $good = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('time.clock', ['type' => 'clock_in', 'occurredAt' => now()->toIso8601String(), 'source' => 'qr', 'qrCode' => $code, 'entryUuid' => (string) Str::uuid()], $user->id),
    ]])->assertOk();

    expect($good->json('results.0.status'))->toBe('ok')
        ->and(TimeEntry::query()->where('user_id', $user->id)->where('source', 'qr')->count())->toBe(1);
});

test('an expired clock QR is rejected', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    $qr = app(ClockQrService::class);
    $old = $qr->codeFor($qr->window(now()->subMinutes(10)));

    $response = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [
        tpvOperation('time.clock', ['type' => 'clock_in', 'occurredAt' => now()->toIso8601String(), 'source' => 'qr', 'qrCode' => $old], $user->id),
    ]])->assertOk();

    expect($response->json('results.0.reason'))->toBe('invalid_clock_qr');
});
