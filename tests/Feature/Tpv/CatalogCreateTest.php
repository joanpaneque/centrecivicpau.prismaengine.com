<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogNames;

test('names are compared without accents, case, punctuation or extra spaces', function () {
    expect(CatalogNames::key('  Croquetes  de PERNIL. '))->toBe('croquetes de pernil')
        ->and(CatalogNames::key('Cafè amb llet'))->toBe(CatalogNames::key('cafe amb  llet'))
        ->and(CatalogNames::key('Ñoquis'))->toBe('noquis');
});

test('the tpv never creates a product whose name already exists, and offers the existing one', function () {
    $user = User::factory()->create();
    $tapas = Category::query()->create(['name' => ['ca' => 'Tapes', 'es' => 'Tapas'], 'sort' => 1]);
    $existing = Product::query()->create(['category_id' => $tapas->id, 'name' => ['ca' => 'Croquetes de pernil', 'es' => 'Croquetas de jamón'], 'price' => 650, 'vat_rate' => 10, 'sort' => 1]);

    $this->actingAs($user)->postJson(route('tpv.products.store'), ['name' => ['ca' => 'croquetas de JAMON', 'es' => 'Croquetas de jamon'], 'categoryId' => $tapas->id])
        ->assertUnprocessable()
        ->assertJsonPath('duplicate.id', $existing->id);

    $this->actingAs($user)->postJson(route('tpv.products.store'), ['name' => ['ca' => 'Patates braves', 'es' => 'Patatas bravas'], 'price' => 500, 'categoryId' => $tapas->id])
        ->assertOk()
        ->assertJsonPath('product.name.ca', 'Patates braves');

    expect(Product::query()->count())->toBe(2);
});

test('admins create families and subfamilies from the tpv, without duplicates and two levels at most', function () {
    $admin = User::factory()->admin()->create();

    $drinks = $this->actingAs($admin)->postJson(route('tpv.categories.store'), ['name' => ['ca' => 'Begudes', 'es' => 'Bebidas'], 'color' => '#2563eb'])
        ->assertOk()
        ->assertJsonPath('category.parentId', null)
        ->json('category.id');

    $beers = $this->actingAs($admin)->postJson(route('tpv.categories.store'), ['name' => ['ca' => 'Cerveses', 'es' => 'Cervezas'], 'parentId' => $drinks])
        ->assertOk()
        ->assertJsonPath('category.parentId', $drinks)
        ->json('category.id');

    $this->actingAs($admin)->postJson(route('tpv.categories.store'), ['name' => ['ca' => 'begudes', 'es' => 'Bebidas']])
        ->assertUnprocessable()
        ->assertJsonPath('duplicate.id', $drinks);

    $this->actingAs($admin)->postJson(route('tpv.categories.store'), ['name' => ['ca' => 'Artesanes', 'es' => 'Artesanas'], 'parentId' => $beers])->assertUnprocessable();

    $this->actingAs(User::factory()->create())->postJson(route('tpv.categories.store'), ['name' => ['ca' => 'Vins', 'es' => 'Vinos']])->assertForbidden();
});
