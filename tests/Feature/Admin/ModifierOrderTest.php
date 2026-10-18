<?php

use App\Models\ModifierGroup;
use App\Models\User;
use App\Services\Devices\DeviceManager;

test('the options of a modifier group keep the order they are saved in, also on the tpv', function () {
    $admin = User::factory()->admin()->create();
    $names = fn (string $name) => ['ca' => $name, 'es' => $name];

    $this->actingAs($admin)->post(route('admin.modifier-groups.store'), [
        'name' => $names('Llet'), 'multiple' => false, 'required' => true,
        'modifiers' => [['name' => $names('Sencera')], ['name' => $names('Desnatada')], ['name' => $names('Civada')]],
    ])->assertRedirect();

    $group = ModifierGroup::query()->latest('id')->firstOrFail();
    [$whole, $skimmed, $oat] = $group->modifiers->all();

    // Oat milk moved to the top, the rest after it.
    $this->actingAs($admin)->patch(route('admin.modifier-groups.update', $group), [
        'name' => $names('Llet'), 'multiple' => false, 'required' => true,
        'modifiers' => [['id' => $oat->id, 'name' => $names('Civada')], ['id' => $whole->id, 'name' => $names('Sencera')], ['id' => $skimmed->id, 'name' => $names('Desnatada')]],
    ])->assertRedirect();

    expect($group->refresh()->modifiers->pluck('id')->all())->toBe([$oat->id, $whole->id, $skimmed->id]);

    $cookie = registerTpvDevice($this, $admin);
    $tpv = collect($this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->getJson(route('tpv.bootstrap'))->json('modifierGroups'))->firstWhere('id', $group->id);

    expect(array_column($tpv['modifiers'], 'id'))->toBe([$oat->id, $whole->id, $skimmed->id]);
});
