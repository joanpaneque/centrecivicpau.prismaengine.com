<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketSeries;
use App\Models\User;
use App\Models\Zone;
use App\Services\Assistant\BusinessTools;
use App\Services\Assistant\MenuAgent;
use App\Services\Assistant\OrderTools;
use App\Services\TimeTracking\WorkedTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function businessTools(): BusinessTools
{
    return new BusinessTools('ca', app(WorkedTime::class));
}

/**
 * @param  list<array{0: string, 1: float, 2: int}>  $lines  name, quantity, total (cents)
 * @param  array<string, int>  $payments
 */
function seedTicket(int $number, CarbonImmutable $at, array $lines, array $payments, ?User $waiter = null, ?string $table = null): Ticket
{
    $series = TicketSeries::query()->firstOrCreate(['code' => 'T1'], ['kind' => 'simplified', 'last_number' => 0, 'active' => true]);
    $total = array_sum(array_column($lines, 2));
    $ticket = Ticket::query()->create([
        'uuid' => (string) Str::uuid(), 'ticket_series_id' => $series->id, 'number' => $number, 'full_number' => 'T1-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
        'table_label' => $table, 'waiter_id' => $waiter?->id, 'issued_at' => $at, 'subtotal' => $total, 'total' => $total,
        'vat_breakdown' => [], 'issuer' => [], 'public_token' => Str::random(32),
    ]);

    foreach ($lines as [$name, $quantity, $lineTotal]) {
        $ticket->lines()->create(['name' => ['ca' => $name, 'es' => $name], 'quantity' => $quantity, 'unit_price' => (int) round($lineTotal / $quantity), 'vat_rate' => 10, 'total' => $lineTotal]);
    }

    foreach ($payments as $method => $amount) {
        $ticket->payments()->create(['uuid' => (string) Str::uuid(), 'method' => $method, 'amount' => $amount]);
    }

    return $ticket;
}

function seedTable(string $zone, string $label): DiningTable
{
    $z = Zone::query()->firstOrCreate(['slug' => Str::slug($zone)], ['name' => ['ca' => $zone, 'es' => $zone], 'sort' => 1]);

    return DiningTable::query()->create(['zone_id' => $z->id, 'label' => $label, 'seats' => 4, 'x' => 0, 'y' => 0, 'width' => 80, 'height' => 80, 'rotation' => 0, 'shape' => 'square', 'is_auxiliary' => false, 'sort' => 1]);
}

test('sales reports are computed from the tickets, grouped as asked', function () {
    $waiter = User::factory()->create(['name' => 'Marta']);
    $monday = CarbonImmutable::parse('2026-10-12 13:30');
    seedTicket(1, $monday, [['Canya', 2, 440], ['Braves', 1, 500]], ['cash' => 940], $waiter, '4');
    seedTicket(2, $monday->addHours(7), [['Canya', 1, 220]], ['card' => 220], null, '2');
    seedTicket(3, $monday->addDay(), [['Menú del dia', 1, 1350]], ['card' => 1350], $waiter);

    $byDay = businessTools()->run('sales_report', ['from' => '2026-10-12', 'to' => '2026-10-13', 'group_by' => 'day']);
    expect($byDay['result']['total'])->toBe(25.1)
        ->and($byDay['result']['tickets'])->toBe(3)
        ->and(array_column($byDay['result']['rows'], 'amount'))->toBe([11.6, 13.5])
        ->and($byDay['preview']['chart']['kind'])->toBe('column');

    $byProduct = businessTools()->run('sales_report', ['from' => '2026-10-12', 'to' => '2026-10-13', 'group_by' => 'product']);
    expect($byProduct['result']['rows'][0])->toMatchArray(['label' => 'Menú del dia', 'amount' => 13.5])
        ->and(collect($byProduct['result']['rows'])->firstWhere('label', 'Canya'))->toMatchArray(['amount' => 6.6, 'quantity' => 3.0]);

    $byMethod = businessTools()->run('sales_report', ['from' => '2026-10-12', 'to' => '2026-10-13', 'group_by' => 'payment_method']);
    expect(array_column($byMethod['result']['rows'], 'amount', 'label'))->toBe(['Targeta' => 15.7, 'Efectiu' => 9.4]);

    $byWaiter = businessTools()->run('sales_report', ['from' => '2026-10-12', 'to' => '2026-10-13', 'group_by' => 'waiter']);
    expect($byWaiter['result']['rows'][0])->toMatchArray(['label' => 'Marta', 'amount' => 22.9]);

    $byHour = businessTools()->run('sales_report', ['from' => '2026-10-12', 'group_by' => 'hour']);
    expect(array_column($byHour['result']['rows'], 'label'))->toBe(['13:00–13:59', '20:00–20:59']);
});

test('tickets can be searched and opened in detail', function () {
    $at = CarbonImmutable::parse('2026-10-14 21:00');
    seedTicket(7, $at, [['Entrecot', 1, 1800], ['Vi negre', 2, 400]], ['card' => 2200], null, '9');
    seedTicket(8, $at, [['Cafè sol', 1, 150]], ['cash' => 150]);

    $search = businessTools()->run('search_tickets', ['from' => '2026-10-14', 'text' => 'entrecot']);
    expect($search['result']['found'])->toBe(1)
        ->and($search['result']['tickets'][0]['number'])->toBe('T1-000007');

    $expensive = businessTools()->run('search_tickets', ['from' => '2026-10-14', 'min_total' => 10]);
    expect($expensive['result']['found'])->toBe(1);

    $detail = businessTools()->run('get_ticket', ['number' => '7']);
    expect($detail['result']['total'])->toEqual(22)
        ->and($detail['result']['lines'])->toHaveCount(2)
        ->and($detail['preview']['links'][0]['url'])->toContain('/gestion/tiquets/');

    expect(businessTools()->run('get_ticket', ['number' => '999'])['result'])->toHaveKey('error');
});

test('the live status shows the open tables', function () {
    $table = seedTable('Sala', '4');
    $order = Order::query()->create(['uuid' => (string) Str::uuid(), 'dining_table_id' => $table->id, 'status' => 'open', 'opened_at' => now()->subMinutes(20)]);
    OrderLine::query()->create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'name' => ['ca' => 'Canya', 'es' => 'Caña'], 'quantity' => 2, 'unit_price' => 220, 'vat_rate' => 10]);

    $live = businessTools()->run('live_status', []);

    expect($live['result']['open_tables'][0])->toMatchArray(['total' => 440])
        ->and($live['result']['open_tables'][0]['table'])->toContain('4');
});

test('dictated orders resolve the table and products and report what is missing', function () {
    seedTable('Sala', '4');
    $category = Category::query()->create(['name' => ['ca' => 'Cafès', 'es' => 'Cafés'], 'sort' => 1]);
    $coffee = Product::query()->create(['category_id' => $category->id, 'name' => ['ca' => 'Tallat', 'es' => 'Cortado'], 'price' => 160, 'vat_rate' => 10, 'sort' => 1]);
    $beer = Product::query()->create(['category_id' => $category->id, 'name' => ['ca' => 'Canya', 'es' => 'Caña'], 'price' => 220, 'vat_rate' => 10, 'sort' => 2]);
    $soldOut = Product::query()->create(['category_id' => $category->id, 'name' => ['ca' => 'Cacaolat', 'es' => 'Cacaolat'], 'price' => 200, 'vat_rate' => 10, 'sort' => 3, 'sold_out' => true]);
    $milk = ModifierGroup::query()->create(['name' => ['ca' => 'Llet', 'es' => 'Leche'], 'multiple' => false, 'required' => true, 'sort' => 1]);
    $oat = $milk->modifiers()->create(['name' => ['ca' => 'De civada', 'es' => 'De avena'], 'price_delta' => 20, 'sort' => 1]);
    $coffee->modifierGroups()->sync([$milk->id]);

    $result = (new OrderTools)->run('add_to_order', ['table' => 'taula 4', 'items' => [
        ['product_id' => $beer->id, 'quantity' => 2],
        ['product_id' => $coffee->id, 'quantity' => 1],
        ['product_id' => $soldOut->id, 'quantity' => 1],
    ]]);

    expect($result['preview']['type'])->toBe('order')
        ->and($result['preview']['lines'])->toHaveCount(1)
        ->and($result['preview']['lines'][0])->toMatchArray(['productId' => $beer->id, 'quantity' => 2])
        ->and($result['preview']['send'])->toBeFalse()
        ->and($result['result']['problems'])->toHaveCount(2)
        ->and(implode(' ', $result['result']['problems']))->toContain('Llet')->toContain('agotado');

    $withMilk = (new OrderTools)->run('add_to_order', ['table' => '4', 'items' => [['product_id' => $coffee->id, 'quantity' => 1, 'option_ids' => [$oat->id]]], 'send' => true]);
    expect($withMilk['preview']['lines'][0]['modifiers'][0]['priceDelta'])->toBe(20)
        ->and($withMilk['preview']['send'])->toBeTrue();

    seedTable('Terrassa', '4');
    expect((new OrderTools)->run('add_to_order', ['table' => '4', 'items' => [['product_id' => $beer->id, 'quantity' => 1]]])['result']['error'])->toContain('Sala')
        ->and((new OrderTools)->run('add_to_order', ['table' => '4', 'zone' => 'terrassa', 'items' => [['product_id' => $beer->id, 'quantity' => 1]]])['preview']['zone']['ca'])->toBe('Terrassa')
        ->and((new OrderTools)->run('add_to_order', ['table' => '99', 'items' => [['product_id' => $beer->id, 'quantity' => 1]]])['result'])->toHaveKey('error');
});

test('each role only gets its own tools', function () {
    $agent = app(MenuAgent::class);

    expect($agent->abilities(User::factory()->admin()->create(), 'tpv'))->toBe(['catalog' => true, 'reports' => true, 'orders' => true])
        ->and($agent->abilities(User::factory()->admin()->create(), 'admin'))->toBe(['catalog' => true, 'reports' => true, 'orders' => false])
        ->and($agent->abilities(User::factory()->create(), 'tpv'))->toBe(['catalog' => false, 'reports' => false, 'orders' => true])
        ->and($agent->abilities(User::factory()->create(['role' => UserRole::Kitchen]), 'tpv'))->toBe(['catalog' => false, 'reports' => false, 'orders' => false]);
});

test('staff can talk to the assistant but cannot use the administrators tools', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['openrouter.ai/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'create_family', 'arguments' => '{"name_ca":"Tapes","name_es":"Tapas"}']]]]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])
        ->push(['choices' => [['message' => ['content' => 'Això ho ha de fer un administrador.']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
    ]);
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->post(route('assistant.chat'), ['message' => 'Crea la família tapes', 'kind' => 'carta', 'context' => 'tpv', 'locale' => 'ca'])
        ->assertOk()
        ->streamedContent();

    expect(Category::query()->where('name->ca', 'Tapes')->exists())->toBeFalse();
    Http::assertSent(fn ($request) => ! str_contains((string) json_encode($request['tools'] ?? []), 'create_family') && str_contains((string) json_encode($request['tools'] ?? []), 'add_to_order'));
});
