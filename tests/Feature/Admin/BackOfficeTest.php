<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('every back office page renders for admins', function (string $route, string $component) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['dashboard', 'Dashboard'],
    ['admin.users', 'admin/Users'],
    ['admin.devices', 'admin/Devices'],
    ['admin.zones', 'admin/Zones'],
    ['admin.catalog', 'admin/Catalog'],
    ['admin.set-menus', 'admin/SetMenus'],
    ['admin.printing', 'admin/Printing'],
    ['admin.printing.log', 'admin/PrintLog'],
    ['admin.tickets', 'admin/Tickets'],
    ['admin.invoices', 'admin/Invoices'],
    ['admin.cash-sessions', 'admin/CashSessions'],
    ['admin.time', 'admin/TimeTracking'],
    ['admin.shifts', 'admin/Shifts'],
    ['admin.suppliers', 'admin/SupplierInvoices'],
    ['admin.assistant', 'admin/Assistant'],
    ['admin.settings', 'admin/Settings'],
    ['admin.audit', 'admin/Audit'],
    ['admin.api-tokens', 'admin/ApiTokens'],
]);

test('staff cannot reach the back office', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.catalog'))->assertForbidden();
});

test('admins manage categories and products', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post(route('admin.categories.store'), ['name' => ['ca' => 'Cafès', 'es' => 'Cafés']])->assertRedirect();
    $category = Category::query()->where('name->ca', 'Cafès')->firstOrFail();

    $this->post(route('admin.products.store'), [
        'name' => ['ca' => 'Tallat', 'es' => 'Cortado'],
        'categoryId' => $category->id,
        'price' => 160,
        'vatRate' => 10,
        'allergens' => ['milk'],
    ])->assertRedirect();

    $product = Product::query()->where('name->ca', 'Tallat')->firstOrFail();

    expect($product->price)->toBe(160)->and($product->allergens)->toBe(['milk']);

    $this->post(route('admin.products.duplicate', $product))->assertRedirect();
    expect(Product::query()->where('category_id', $category->id)->count())->toBe(2);

    $this->delete(route('admin.categories.destroy', $category))->assertRedirect();
    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue();

    Product::query()->where('category_id', $category->id)->get()->each(fn (Product $p) => $this->delete(route('admin.products.destroy', $p)));
    $this->delete(route('admin.categories.destroy', $category))->assertRedirect();
    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse();
});

test('product prices are validated', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::query()->create(['name' => ['ca' => 'Begudes', 'es' => 'Bebidas'], 'sort' => 1]);

    $this->actingAs($admin)->post(route('admin.products.store'), [
        'name' => ['ca' => 'Aigua', 'es' => 'Agua'],
        'categoryId' => $category->id,
        'price' => -1,
        'vatRate' => 7,
    ])->assertSessionHasErrors(['price', 'vatRate']);
});

test('zones get a unique slug', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post(route('admin.zones.store'), ['name' => ['ca' => 'Terrassa', 'es' => 'Terraza'], 'appliesTerraceSurcharge' => true])->assertRedirect();
    $this->post(route('admin.zones.store'), ['name' => ['ca' => 'Terrassa', 'es' => 'Terraza']])->assertRedirect();

    expect(Zone::query()->where('slug', 'like', 'terrassa%')->pluck('slug')->sort()->values()->all())->toBe(['terrassa', 'terrassa-2']);
});

test('shifts can be moved or copied', function () {
    $admin = User::factory()->admin()->create();
    $worker = User::factory()->create();
    $this->actingAs($admin);

    $this->post(route('admin.shifts.store'), ['userId' => $worker->id, 'date' => '2026-10-12', 'startTime' => '08:00', 'endTime' => '14:00'])->assertRedirect();
    $shift = Shift::query()->where('user_id', $worker->id)->firstOrFail();

    $this->patch(route('admin.shifts.update', $shift), ['date' => '2026-10-13'])->assertRedirect();
    expect($shift->fresh()?->date->toDateString())->toBe('2026-10-13');

    $this->patch(route('admin.shifts.update', $shift), ['date' => '2026-10-14', 'copy' => true])->assertRedirect();
    expect(Shift::query()->where('user_id', $worker->id)->orderBy('date')->get()->map(fn (Shift $s) => $s->date->toDateString())->all())
        ->toBe(['2026-10-13', '2026-10-14']);
});

test('supplier invoices are uploaded privately', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.suppliers.store'), [
        'file' => UploadedFile::fake()->image('factura.jpg', 1200, 1600),
        'supplier' => 'Distribucions Pau',
        'invoiceDate' => '2026-10-01',
        'amount' => 12345,
    ])->assertRedirect();

    $invoice = SupplierInvoice::query()->firstOrFail();

    expect($invoice->supplier)->toBe('Distribucions Pau')
        ->and($invoice->amount)->toBe(12345);

    Storage::disk('local')->assertExists($invoice->path);

    $this->get(route('admin.suppliers.file', $invoice))->assertOk();
});
