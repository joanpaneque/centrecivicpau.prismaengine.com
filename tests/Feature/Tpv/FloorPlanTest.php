<?php

use App\Models\DiningTable;
use App\Models\FloorElement;
use App\Models\User;
use App\Models\Zone;
use App\Services\Devices\DeviceManager;

function floorZone(): Zone
{
    return Zone::query()->create(['name' => ['ca' => 'Sala', 'es' => 'Sala'], 'slug' => 'sala-'.uniqid()]);
}

test('staff cannot edit the floor plan', function () {
    $zone = floorZone();

    $this->actingAs(User::factory()->create())
        ->postJson(route('tpv.elements.store'), ['zoneId' => $zone->id, 'type' => 'bar'])
        ->assertForbidden();
});

test('admins can add, change and remove floor elements', function () {
    $admin = User::factory()->admin()->create();
    $zone = floorZone();

    $created = $this->actingAs($admin)
        ->postJson(route('tpv.elements.store'), ['zoneId' => $zone->id, 'type' => 'bar', 'x' => 40, 'y' => 60])
        ->assertOk()
        ->assertJsonPath('element.type', 'bar')
        ->assertJsonPath('element.x', 40)
        ->assertJsonPath('element.width', FloorElement::SIZES['bar'][0]);

    $id = $created->json('element.id');

    $this->actingAs($admin)
        ->patchJson(route('tpv.elements.update', $id), ['label' => 'Barra gran', 'rotation' => -90, 'color' => '#334155', 'sort' => 5])
        ->assertOk()
        ->assertJsonPath('element.label', 'Barra gran')
        ->assertJsonPath('element.rotation', 270)
        ->assertJsonPath('element.sort', 5);

    $this->actingAs($admin)->deleteJson(route('tpv.elements.destroy', $id))->assertOk();

    expect(FloorElement::query()->find($id))->toBeNull()
        ->and(FloorElement::withTrashed()->find($id))->not->toBeNull();
});

test('floor elements reject unknown types and bad colours', function () {
    $admin = User::factory()->admin()->create();
    $zone = floorZone();

    $this->actingAs($admin)
        ->postJson(route('tpv.elements.store'), ['zoneId' => $zone->id, 'type' => 'piano', 'color' => 'red'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'color']);
});

test('the layout endpoint saves position, size and rotation of tables and elements', function () {
    $admin = User::factory()->admin()->create();
    $zone = floorZone();
    $table = DiningTable::query()->create(['zone_id' => $zone->id, 'label' => 'T1']);
    $element = FloorElement::query()->create(['zone_id' => $zone->id, 'type' => 'wall', 'width' => 200, 'height' => 12]);

    $this->actingAs($admin)->postJson(route('tpv.tables.layout'), [
        'tables' => [['id' => $table->id, 'x' => 120, 'y' => 80, 'width' => 140, 'height' => 70, 'rotation' => 45]],
        'elements' => [['id' => $element->id, 'x' => -30, 'y' => 300, 'width' => 400, 'rotation' => 90]],
    ])->assertOk();

    expect($table->fresh())
        ->x->toBe(120)->y->toBe(80)->width->toBe(140)->height->toBe(70)->rotation->toBe(45)
        ->and($element->fresh())
        ->x->toBe(-30)->y->toBe(300)->width->toBe(400)->height->toBe(12)->rotation->toBe(90);
});

test('the pos snapshot includes floor elements and table rotation', function () {
    $user = User::factory()->create();
    $zone = floorZone();
    DiningTable::query()->create(['zone_id' => $zone->id, 'label' => 'T1', 'rotation' => 90]);
    FloorElement::query()->create(['zone_id' => $zone->id, 'type' => 'door', 'label' => 'Entrada']);
    $cookie = registerTpvDevice($this, $user);

    $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)
        ->getJson(route('tpv.bootstrap'))
        ->assertOk()
        ->assertJsonPath('tables.0.rotation', 90)
        ->assertJsonPath('floorElements.0.type', 'door')
        ->assertJsonPath('floorElements.0.label', 'Entrada')
        ->assertJsonPath('floorElements.0.zoneId', $zone->id);
});
