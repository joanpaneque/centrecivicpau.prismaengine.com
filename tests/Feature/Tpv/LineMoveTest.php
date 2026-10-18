<?php

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Zone;
use App\Services\Devices\DeviceManager;
use Illuminate\Support\Str;

function moveTable(string $label): DiningTable
{
    $zone = Zone::query()->firstOrCreate(['slug' => 'sala'], ['name' => ['ca' => 'Sala', 'es' => 'Sala'], 'sort' => 1]);

    return DiningTable::query()->create(['zone_id' => $zone->id, 'label' => $label, 'seats' => 4, 'x' => 0, 'y' => 0, 'width' => 80, 'height' => 80, 'rotation' => 0, 'shape' => 'square', 'is_auxiliary' => false, 'sort' => 1]);
}

/**
 * @param  list<array<string, mixed>>  $lines
 */
function sendToTable(mixed $test, string $cookie, User $user, DiningTable $table, array $lines): Order
{
    $uuid = (string) Str::uuid();
    $test->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [tpvOperation('order.send', [
        'orderUuid' => $uuid, 'tableId' => $table->id, 'kitchenTickets' => [],
        'lines' => array_map(fn ($l) => $l + ['uuid' => (string) Str::uuid(), 'vatRate' => 10, 'modifiers' => [], 'children' => []], $lines),
    ], $user->id)]])->assertOk()->assertJsonPath('results.0.status', 'ok');

    return Order::query()->where('uuid', $uuid)->firstOrFail();
}

function pushMove(mixed $test, string $cookie, User $user, string $lineUuid, int $quantity, DiningTable $to): mixed
{
    return $test->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => [tpvOperation('line.move', [
        'lineUuid' => $lineUuid, 'quantity' => $quantity, 'toTableId' => $to->id, 'newOrderUuid' => (string) Str::uuid(), 'newLineUuid' => (string) Str::uuid(),
    ], $user->id)]])->assertOk();
}

test('some units of a product go to a free table, which gets its own bill', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    [$t1, $t2] = [moveTable('1'), moveTable('2')];
    $order = sendToTable($this, $cookie, $user, $t1, [
        ['name' => ['ca' => 'Canya', 'es' => 'Caña'], 'quantity' => 3, 'unitPrice' => 250],
        ['name' => ['ca' => 'Braves', 'es' => 'Bravas'], 'quantity' => 1, 'unitPrice' => 500],
    ]);
    $beer = $order->lines()->where('quantity', 3)->firstOrFail();

    pushMove($this, $cookie, $user, $beer->uuid, 1, $t2)->assertJsonPath('results.0.status', 'ok');

    $target = Order::query()->where('dining_table_id', $t2->id)->whereIn('status', Order::ACTIVE_STATUSES)->firstOrFail();

    expect($beer->refresh()->quantity)->toBe(2)
        ->and($beer->order_id)->toBe($order->id)
        ->and($target->lines()->sole()->quantity)->toBe(1)
        ->and($target->lines()->sole()->unit_price)->toBe(250)
        ->and($order->refresh()->status)->toBe('open');
});

test('a whole product goes to an occupied table, and a table left empty is joined into it', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    [$t1, $t2] = [moveTable('1'), moveTable('2')];
    $source = sendToTable($this, $cookie, $user, $t1, [['name' => ['ca' => 'Cafè', 'es' => 'Café'], 'quantity' => 2, 'unitPrice' => 150]]);
    $target = sendToTable($this, $cookie, $user, $t2, [['name' => ['ca' => 'Aigua', 'es' => 'Agua'], 'quantity' => 1, 'unitPrice' => 200]]);
    $coffee = $source->lines()->firstOrFail();

    pushMove($this, $cookie, $user, $coffee->uuid, 2, $t2)->assertJsonPath('results.0.status', 'ok');

    expect($coffee->refresh()->order_id)->toBe($target->id)
        ->and($target->lines()->count())->toBe(2)
        ->and($source->refresh()->status)->toBe('merged')
        ->and($source->merged_into_id)->toBe($target->id);
});

test('paid units never move', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    [$t1, $t2] = [moveTable('1'), moveTable('2')];
    $order = sendToTable($this, $cookie, $user, $t1, [['name' => ['ca' => 'Canya', 'es' => 'Caña'], 'quantity' => 3, 'unitPrice' => 250]]);
    $line = $order->lines()->firstOrFail();
    $line->forceFill(['paid_quantity' => 2])->save();

    pushMove($this, $cookie, $user, $line->uuid, 3, $t2);

    $moved = OrderLine::query()->where('order_id', '!=', $order->id)->sole();
    expect($line->refresh()->quantity)->toBe(2)
        ->and($line->paid_quantity)->toBe(2)
        ->and($moved->quantity)->toBe(1)
        ->and($moved->paid_quantity)->toBe(0);
});
