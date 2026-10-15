<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Assistant\ProductNameSuggester;
use App\Services\Devices\DeviceManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function speedupCategory(string $name = 'Cerveses'): Category
{
    return Category::query()->create(['name' => ['ca' => $name, 'es' => $name], 'sort' => 1]);
}

function speedupProduct(Category $category, ?int $price): Product
{
    return Product::query()->create([
        'category_id' => $category->id,
        'name' => ['ca' => 'Canya', 'es' => 'Caña'],
        'price' => $price,
        'vat_rate' => 10,
        'sort' => 1,
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $operations
 */
function speedupPush(mixed $test, string $cookie, array $operations): void
{
    $response = $test->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->postJson(route('tpv.push'), ['operations' => $operations])->assertOk();

    foreach ($operations as $index => $operation) {
        $response->assertJsonPath("results.{$index}.status", 'ok');
    }
}

test('a product without price is sent as an unpriced line and priced later from the gear', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    $product = speedupProduct(speedupCategory(), null);
    $orderUuid = (string) Str::uuid();
    $lineUuid = (string) Str::uuid();

    speedupPush($this, $cookie, [tpvOperation('order.send', [
        'orderUuid' => $orderUuid,
        'tableId' => null,
        'label' => 'Barra',
        'lines' => [[
            'uuid' => $lineUuid,
            'productId' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'unitPrice' => 0,
            'unpriced' => true,
            'vatRate' => 10,
            'modifiers' => [],
            'children' => [],
        ]],
        'kitchenTickets' => [],
    ], $user->id)]);

    $line = OrderLine::query()->where('uuid', $lineUuid)->firstOrFail();
    expect($line->unpriced)->toBeTrue()->and($line->unit_price)->toBe(0);

    $snapshot = $this->withCredentials()->withCookie(DeviceManager::COOKIE, $cookie)->getJson(route('tpv.bootstrap'))->assertOk();
    $orders = collect($snapshot->json('orders'))->keyBy('uuid');
    expect($orders[$orderUuid]['lines'][0]['unpriced'])->toBeTrue()
        ->and(collect($snapshot->json('products'))->firstWhere('id', $product->id)['price'])->toBeNull();

    speedupPush($this, $cookie, [
        tpvOperation('line.edit', ['lineUuids' => [$lineUuid], 'unitPrice' => 180, 'name' => ['ca' => 'Canya petita', 'es' => 'Caña pequeña']], $user->id),
        tpvOperation('product.edit', ['productId' => $product->id, 'price' => 180], $user->id),
    ]);

    $line->refresh();
    expect($line->unpriced)->toBeFalse()
        ->and($line->unit_price)->toBe(180)
        ->and($line->name)->toBe(['ca' => 'Canya petita', 'es' => 'Caña pequeña'])
        ->and($product->refresh()->price)->toBe(180)
        ->and($product->name)->toBe(['ca' => 'Canya', 'es' => 'Caña'])
        ->and(Order::query()->where('uuid', $orderUuid)->firstOrFail()->status)->toBe('open');
});

test('a line can be voided without a reason', function () {
    $user = User::factory()->create();
    $cookie = registerTpvDevice($this, $user);
    $orderUuid = (string) Str::uuid();

    speedupPush($this, $cookie, [tpvOperation('order.send', [
        'orderUuid' => $orderUuid,
        'tableId' => null,
        'label' => 'Barra',
        'lines' => [['uuid' => (string) Str::uuid(), 'name' => ['ca' => 'Cafè', 'es' => 'Café'], 'quantity' => 2, 'unitPrice' => 150, 'vatRate' => 10, 'modifiers' => [], 'children' => []]],
        'kitchenTickets' => [],
    ], $user->id)]);
    $line = Order::query()->where('uuid', $orderUuid)->firstOrFail()->lines()->firstOrFail();

    speedupPush($this, $cookie, [tpvOperation('line.void', ['lineUuid' => $line->uuid, 'quantity' => 2, 'reason' => null], $user->id)]);

    expect($line->refresh()->voided_at)->not->toBeNull()->and($line->void_reason)->toBeNull();
});

test('waiters add invented products to the catalog from the pos', function () {
    $user = User::factory()->create();
    $category = speedupCategory('Tapes');

    $response = $this->actingAs($user)->postJson(route('tpv.products.store'), [
        'name' => ['ca' => 'Croquetes de pernil', 'es' => 'Croquetas de jamón'],
        'price' => null,
        'categoryId' => $category->id,
    ])->assertOk();

    $product = Product::query()->findOrFail($response->json('product.id'));

    expect($product->price)->toBeNull()
        ->and($product->category_id)->toBe($category->id)
        ->and($response->json('product.name.es'))->toBe('Croquetas de jamón');
});

test('the ai suggestion is skipped when no provider is configured', function () {
    config(['services.openrouter.key' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('tpv.products.suggest'), ['name' => 'croq. jamon'])
        ->assertOk()
        ->assertJsonPath('suggestion', null);
});

test('the ai suggestion returns both names and a known family', function () {
    config(['services.openrouter.key' => 'test-key']);
    $category = speedupCategory('Tapes');
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Aquí tens: {"ca": "Croquetes de pernil", "es": "Croquetas de jamón", "categoryId": '.$category->id.'}']]]])]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('tpv.products.suggest'), ['name' => 'croq. jamon'])
        ->assertOk()
        ->assertJsonPath('suggestion.ca', 'Croquetes de pernil')
        ->assertJsonPath('suggestion.es', 'Croquetas de jamón')
        ->assertJsonPath('suggestion.categoryId', $category->id);
});

test('an unknown family proposed by the ai is dropped', function () {
    $suggester = app(ProductNameSuggester::class);

    expect($suggester->parse('{"ca": "Bravas", "es": "Bravas", "categoryId": 999}', collect([1 => 'Tapes'])))->toBe(['ca' => 'Bravas', 'es' => 'Bravas', 'categoryId' => null])
        ->and($suggester->parse('no json here', collect()))->toBeNull();
});

test('admins upload a family image and save products without price', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $category = speedupCategory('Cafès');

    $this->actingAs($admin)->post(route('admin.categories.update', $category), [
        'name' => ['ca' => 'Cafès', 'es' => 'Cafés'],
        'photo' => UploadedFile::fake()->image('cafe.png', 400, 300),
    ])->assertRedirect();

    $category->refresh();
    expect($category->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($category->photo_path);

    $this->post(route('admin.products.store'), [
        'name' => ['ca' => 'Infusió', 'es' => 'Infusión'],
        'categoryId' => $category->id,
        'price' => null,
        'vatRate' => 10,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Product::query()->where('name->ca', 'Infusió')->firstOrFail()->price)->toBeNull();
});
