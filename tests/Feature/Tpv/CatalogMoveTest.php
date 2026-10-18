<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

function moveFamily(string $name, ?int $parentId = null): Category
{
    return Category::query()->create(['name' => ['ca' => $name, 'es' => $name], 'parent_id' => $parentId, 'sort' => 1]);
}

test('admins move a product to another family from the pos, at the end', function () {
    $admin = User::factory()->admin()->create();
    $drinks = moveFamily('Begudes');
    $tapas = moveFamily('Tapes');
    Product::query()->create(['category_id' => $tapas->id, 'name' => ['ca' => 'Braves', 'es' => 'Bravas'], 'price' => 500, 'vat_rate' => 10, 'sort' => 4]);
    $olives = Product::query()->create(['category_id' => $drinks->id, 'name' => ['ca' => 'Olives', 'es' => 'Aceitunas'], 'price' => 300, 'vat_rate' => 10, 'sort' => 1]);

    $this->actingAs($admin)->postJson(route('tpv.catalog.move'), ['kind' => 'product', 'id' => $olives->id, 'targetId' => $tapas->id])
        ->assertOk()
        ->assertJsonPath('product.categoryId', $tapas->id);

    expect($olives->refresh()->category_id)->toBe($tapas->id)->and($olives->sort)->toBe(5);

    $this->actingAs($admin)->postJson(route('tpv.catalog.move'), ['kind' => 'product', 'id' => $olives->id, 'targetId' => null])->assertUnprocessable();
});

test('families move into a main family or out to the main ones, two levels at most', function () {
    $admin = User::factory()->admin()->create();
    $tapas = moveFamily('Tapes');
    $cold = moveFamily('Fredes', $tapas->id);
    $drinks = moveFamily('Begudes');

    $this->actingAs($admin)->postJson(route('tpv.catalog.move'), ['kind' => 'family', 'id' => $cold->id, 'targetId' => null])
        ->assertOk()
        ->assertJsonPath('category.parentId', null);

    $this->actingAs($admin)->postJson(route('tpv.catalog.move'), ['kind' => 'family', 'id' => $cold->id, 'targetId' => $drinks->id])->assertOk();
    expect($cold->refresh()->parent_id)->toBe($drinks->id);

    // Not into a subfamily, not into itself, and not a family that has subfamilies.
    $this->actingAs($admin)->postJson(route('tpv.catalog.move'), ['kind' => 'family', 'id' => $tapas->id, 'targetId' => $cold->id])->assertUnprocessable();
    $this->actingAs($admin)->postJson(route('tpv.catalog.move'), ['kind' => 'family', 'id' => $tapas->id, 'targetId' => $tapas->id])->assertUnprocessable();
    $this->actingAs($admin)->postJson(route('tpv.catalog.move'), ['kind' => 'family', 'id' => $drinks->id, 'targetId' => $tapas->id])->assertUnprocessable();
});

test('only admins move the catalog', function () {
    $waiter = User::factory()->create();
    $tapas = moveFamily('Tapes');
    $drinks = moveFamily('Begudes');

    $this->actingAs($waiter)->postJson(route('tpv.catalog.move'), ['kind' => 'family', 'id' => $drinks->id, 'targetId' => $tapas->id])->assertForbidden();
});
