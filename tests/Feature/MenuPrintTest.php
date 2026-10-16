<?php

use App\Jobs\TranslateSetMenu;
use App\Models\SetMenu;
use App\Models\User;
use App\Services\Assistant\MenuTranslator;
use App\Services\Catalog\MenuDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function printableMenu(): SetMenu
{
    $menu = SetMenu::query()->create([
        'name' => ['ca' => 'Menú del dia', 'es' => 'Menú del día'],
        'includes' => ['ca' => 'Pa i beguda', 'es' => 'Pan y bebida'],
        'price' => 1350, 'vat_rate' => 10, 'schedule_type' => 'always', 'active' => true, 'sort' => 1,
    ]);
    $first = $menu->sections()->create(['name' => ['ca' => 'Primers', 'es' => 'Primeros'], 'choices' => 1, 'course' => 1, 'sort' => 0]);
    $first->items()->create(['name' => ['ca' => 'Amanida', 'es' => 'Ensalada'], 'supplement' => 0, 'sort' => 0]);
    $first->items()->create(['name' => ['ca' => 'Sopa', 'es' => 'Sopa'], 'supplement' => 0, 'sort' => 1]);
    $second = $menu->sections()->create(['name' => ['ca' => 'Segons', 'es' => 'Segundos'], 'choices' => 1, 'course' => 2, 'sort' => 1]);
    $second->items()->create(['name' => ['ca' => 'Entrecot', 'es' => 'Entrecot'], 'supplement' => 300, 'sort' => 0]);

    return $menu->fresh() ?? $menu;
}

/**
 * @param  list<string>  $locales
 */
function fakeMenuTranslation(array $locales): void
{
    config(['services.openrouter.key' => 'test-key']);
    $translations = [];

    foreach ($locales as $locale) {
        $translations[$locale] = [
            'name' => strtoupper($locale).' Daily menu',
            'includes' => strtoupper($locale).' Bread and drink',
            'sections' => [
                ['name' => strtoupper($locale).' Starters', 'items' => [strtoupper($locale).' Salad', strtoupper($locale).' Soup']],
                ['name' => strtoupper($locale).' Mains', 'items' => [strtoupper($locale).' Sirloin']],
            ],
        ];
    }

    Http::preventStrayRequests();
    Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => json_encode($translations, JSON_UNESCAPED_UNICODE)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]])]);
}

test('the printed menu has one a4 page per language with the chosen design', function () {
    fakeMenuTranslation(['fr', 'ru']);
    $admin = User::factory()->admin()->create();
    $menu = printableMenu();

    $response = $this->actingAs($admin)->get(route('admin.set-menus.pdf', ['menu' => $menu, 'template' => 'christmas', 'langs' => 'ca,fr,ru']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(preg_match_all('#/Type\s*/Page[^s]#', (string) $response->getContent()))->toBe(3);

    $menu->refresh();
    expect($menu->translations['locales']['fr']['name'])->toBe('FR Daily menu')
        ->and($menu->translations['locales']['ru']['sections'][1]['items'][0])->toBe('RU Sirloin')
        ->and($menu->translations['hash'])->toBe(app(MenuDocument::class)->hash($menu));

    // Already translated: printing again does not call the AI.
    Http::fake(['openrouter.ai/*' => Http::response([], 500)]);
    $this->actingAs($admin)->get(route('admin.set-menus.pdf', ['menu' => $menu, 'langs' => 'fr']))->assertOk();
});

test('changing the menu makes its translations stale', function () {
    $menu = printableMenu();
    $document = app(MenuDocument::class);
    $menu->forceFill(['translations' => ['hash' => $document->hash($menu), 'locales' => ['en' => ['name' => 'Menu']]]])->save();

    expect($document->missing($menu, ['ca', 'en', 'de']))->toBe(['de']);

    $menu->sections()->first()?->items()->first()?->update(['name' => ['ca' => 'Escalivada', 'es' => 'Escalivada']]);
    $menu = $menu->fresh() ?? $menu;

    expect($document->missing($menu, ['en']))->toBe(['en'])
        ->and($document->localized($menu, 'en')['name'])->toBe('Menú del día');
});

test('translations with a different structure are discarded', function () {
    $menu = printableMenu();
    $translator = app(MenuTranslator::class);
    $source = app(MenuDocument::class)->source($menu);

    $bad = json_encode(['de' => ['name' => 'Menü', 'sections' => [['name' => 'Vorspeisen', 'items' => ['Salat']]]]]);
    $good = json_encode(['de' => ['name' => 'Tagesmenü', 'includes' => null, 'sections' => [['name' => 'Vorspeisen', 'items' => ['Salat', 'Suppe']], ['name' => 'Hauptgerichte', 'items' => ['Entrecôte']]]]]);

    expect($translator->parse((string) $bad, $source, ['de']))->toBe([])
        ->and($translator->parse('Aquí va: '.$good, $source, ['de'])['de']['sections'][1]['items'])->toBe(['Entrecôte']);
});

test('the print preview is the page as html and the design is remembered', function () {
    $admin = User::factory()->admin()->create();
    $menu = printableMenu();

    $this->actingAs($admin)->get(route('admin.set-menus.preview', ['menu' => $menu, 'template' => 'kids', 'lang' => 'es']))
        ->assertOk()
        ->assertSee('Primeros')
        ->assertSee('13,50 €');

    $this->actingAs($admin)->patchJson(route('admin.set-menus.template', $menu), ['template' => 'halloween'])->assertOk();
    $this->actingAs($admin)->patchJson(route('admin.set-menus.template', $menu), ['template' => 'disco'])->assertUnprocessable();

    expect($menu->refresh()->template)->toBe('halloween');
});

test('saving a menu queues its translation', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.set-menus.store'), [
        'name' => ['ca' => 'Menú infantil', 'es' => 'Menú infantil'],
        'price' => 900,
        'vatRate' => 10,
        'scheduleType' => 'always',
        'template' => 'kids',
        'sections' => [['name' => ['ca' => 'Plat', 'es' => 'Plato'], 'items' => [['name' => ['ca' => 'Macarrons', 'es' => 'Macarrones']]]]],
    ])->assertRedirect();

    $menu = SetMenu::query()->where('name->ca', 'Menú infantil')->firstOrFail();
    expect($menu->template)->toBe('kids');
    Queue::assertPushed(TranslateSetMenu::class, fn (TranslateSetMenu $job) => $job->menuId === $menu->id);
});

test('every page fits on one sheet even for a long menu', function () {
    $menu = printableMenu();
    $section = $menu->sections()->create(['name' => ['ca' => 'Postres', 'es' => 'Postres'], 'choices' => 2, 'sort' => 2]);

    foreach (range(1, 14) as $i) {
        $section->items()->create(['name' => ['ca' => "Postre casolà número {$i} amb fruita i nata", 'es' => "Postre casero número {$i} con fruta y nata"], 'sort' => $i]);
    }

    $pdf = app(MenuDocument::class)->pdf($menu->fresh() ?? $menu, 'easter', ['ca', 'es']);

    expect(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBe(2);
});
