<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductionDestination;
use App\Models\User;

test('families send all their products to a destination and a product can go elsewhere', function () {
    $admin = User::factory()->admin()->create();
    $bar = ProductionDestination::query()->create(['name' => ['ca' => 'Barra', 'es' => 'Barra'], 'code' => 'bar', 'mode' => 'printer', 'sort' => 1]);
    $kitchen = ProductionDestination::query()->create(['name' => ['ca' => 'Cuina', 'es' => 'Cocina'], 'code' => 'kitchen', 'mode' => 'both', 'sort' => 2]);

    $tapas = Category::query()->create(['name' => ['ca' => 'Tapes', 'es' => 'Tapas'], 'sort' => 1]);
    $cold = Category::query()->create(['name' => ['ca' => 'Fredes', 'es' => 'Frías'], 'parent_id' => $tapas->id, 'sort' => 2]);
    $patatas = Product::query()->create(['category_id' => $cold->id, 'name' => ['ca' => 'Braves', 'es' => 'Bravas'], 'price' => 500, 'vat_rate' => 10, 'sort' => 1]);
    $olives = Product::query()->create(['category_id' => $cold->id, 'name' => ['ca' => 'Olives', 'es' => 'Aceitunas'], 'price' => 300, 'vat_rate' => 10, 'sort' => 2]);

    $this->actingAs($admin)->get(route('admin.printing'))->assertOk();

    $this->actingAs($admin)->patch(route('admin.printing.families.update', $tapas), ['destinationId' => $kitchen->id])->assertRedirect();
    $this->actingAs($admin)->patch(route('admin.printing.products.update', $olives), ['destinationId' => $bar->id])->assertRedirect();

    // The subfamily inherits the family's; the product's own wins.
    expect($patatas->refresh()->effectiveDestinationId())->toBe($kitchen->id)
        ->and($olives->refresh()->effectiveDestinationId())->toBe($bar->id);

    $this->actingAs($admin)->patch(route('admin.printing.products.update', $olives), ['destinationId' => null])->assertRedirect();
    expect($olives->refresh()->effectiveDestinationId())->toBe($kitchen->id);

    $this->actingAs($admin)->patch(route('admin.printing.families.update', $tapas), ['destinationId' => 999])->assertSessionHasErrors('destinationId');
});
