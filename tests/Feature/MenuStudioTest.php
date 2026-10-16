<?php

use App\Models\AssistantConversation;
use App\Models\Category;
use App\Models\Product;
use App\Models\SetMenu;
use App\Models\User;
use App\Services\Assistant\MenuAgent;
use App\Services\Assistant\MenuTools;
use App\Services\Catalog\SetMenuWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function studioTools(User $user): MenuTools
{
    return new MenuTools($user, app(SetMenuWriter::class));
}

/**
 * @param  array<string, mixed>  $args
 * @return array<string, mixed>
 */
function toolCall(string $name, array $args, string $id = 'call_1'): array
{
    return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]];
}

test('only admins can use carta amb ia', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('admin.menu-studio'))->assertForbidden();
    $this->actingAs($staff)->getJson(route('assistant.index', ['kind' => 'carta']))->assertForbidden();

    config(['services.openrouter.key' => 'test-key']);
    $this->actingAs($staff)->postJson(route('assistant.chat'), ['message' => 'Crea una família', 'kind' => 'carta'])->assertForbidden();
});

test('the tools create families and products in the requested position', function () {
    $admin = User::factory()->admin()->create();
    $tools = studioTools($admin);

    $family = $tools->run('create_family', ['name_ca' => 'Tapes', 'name_es' => 'Tapas', 'color' => '#0F766E']);
    $familyId = $family['result']['family_id'];

    $tools->run('create_product', ['family_id' => $familyId, 'name_ca' => 'Braves', 'name_es' => 'Bravas', 'price' => 5]);
    $tools->run('create_product', ['family_id' => $familyId, 'name_ca' => 'Calamars', 'name_es' => 'Calamares']);
    $created = $tools->run('create_product', ['family_id' => $familyId, 'name_ca' => 'Croquetes', 'name_es' => 'Croquetas', 'price' => '6,5', 'position' => 1]);

    expect($created['action']['label'])->toContain('Croquetes')
        ->and($created['preview']['type'])->toBe('family')
        ->and(array_map(fn ($p) => $p['name']['ca'], $created['preview']['products']))->toBe(['Croquetes', 'Braves', 'Calamars'])
        ->and(Category::query()->findOrFail($familyId)->color)->toBe('#0f766e')
        ->and(Product::query()->where('name->ca', 'Croquetes')->value('price'))->toBe(650)
        ->and(Product::query()->where('name->ca', 'Calamars')->value('price'))->toBeNull()
        ->and($tools->changed())->toBeTrue();

    $update = $tools->run('update_product', ['id' => Product::query()->where('name->ca', 'Calamars')->value('id'), 'price' => 7.5, 'position' => 1]);
    expect($update['preview']['products'][0]['name']['ca'])->toBe('Calamars')
        ->and($update['preview']['products'][0]['price'])->toBe(750);
});

test('deleting needs explicit confirmation and errors go back to the model', function () {
    $admin = User::factory()->admin()->create();
    $tools = studioTools($admin);
    $familyId = $tools->run('create_family', ['name_ca' => 'Postres', 'name_es' => 'Postres'])['result']['family_id'];
    $productId = $tools->run('create_product', ['family_id' => $familyId, 'name_ca' => 'Flam', 'name_es' => 'Flan'])['result']['product_id'];

    expect($tools->run('delete_product', ['id' => $productId, 'confirmed' => false])['result'])->toHaveKey('error')
        ->and(Product::query()->whereKey($productId)->exists())->toBeTrue()
        ->and($tools->run('create_product', ['family_id' => 999, 'name_ca' => 'X', 'name_es' => 'X'])['result'])->toHaveKey('error');

    $tools->run('delete_product', ['id' => $productId, 'confirmed' => true]);
    expect(Product::query()->whereKey($productId)->exists())->toBeFalse();
});

test('the tools write a complete set menu and preview it', function () {
    $admin = User::factory()->admin()->create();
    $tools = studioTools($admin);
    $familyId = $tools->run('create_family', ['name_ca' => 'Plats', 'name_es' => 'Platos'])['result']['family_id'];
    $productId = $tools->run('create_product', ['family_id' => $familyId, 'name_ca' => 'Macarrons', 'name_es' => 'Macarrones'])['result']['product_id'];

    $result = $tools->run('save_set_menu', [
        'id' => null,
        'name_ca' => 'Menú de dilluns',
        'name_es' => 'Menú del lunes',
        'price' => 13.5,
        'includes_ca' => 'Pa, beguda i postres',
        'schedule' => 'weekdays',
        'weekdays' => [1],
        'sections' => [
            ['name_ca' => 'Primers', 'name_es' => 'Primeros', 'course' => 1, 'items' => [['product_id' => $productId], ['name_ca' => 'Amanida', 'name_es' => 'Ensalada']]],
            ['name_ca' => 'Segons', 'name_es' => 'Segundos', 'course' => 2, 'items' => [['name_ca' => 'Entrecot', 'name_es' => 'Entrecot', 'supplement' => 3]]],
        ],
    ]);

    $menu = SetMenu::query()->with('sections.items')->findOrFail($result['result']['menu_id']);

    expect($menu->price)->toBe(1350)
        ->and($menu->weekdays)->toBe([1])
        ->and($menu->includes)->toBe(['ca' => 'Pa, beguda i postres', 'es' => 'Pa, beguda i postres'])
        ->and($menu->sections)->toHaveCount(2)
        ->and($menu->sections[1]->items[0]->supplement)->toBe(300)
        ->and($result['preview']['type'])->toBe('set_menu')
        ->and($result['preview']['sections'][0]['items'][0]['name']['ca'])->toBe('Macarrons');

    $this->actingAs($admin)->get(route('admin.set-menus.pdf', $menu))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($admin)->get(route('admin.catalog.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('a carta chat runs the tools, streams the actions and offers answer buttons', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['openrouter.ai/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [toolCall('create_family', ['name_ca' => 'Tapes', 'name_es' => 'Tapas'])]]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]])
        ->push(['choices' => [['message' => ['content' => 'He creat la família Tapes.', 'tool_calls' => [toolCall('ask_user', ['question' => 'Hi afegeixo productes?', 'options' => ['Sí, braves i croquetes', 'Està bé així']], 'call_2')]]]], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30]]),
    ]);
    $admin = User::factory()->admin()->create();

    $body = $this->actingAs($admin)
        ->post(route('assistant.chat'), ['message' => 'Crea la família tapes', 'kind' => 'carta', 'context' => 'admin', 'locale' => 'ca'])
        ->assertOk()
        ->streamedContent();

    expect($body)->toContain('"type":"action"')->toContain('Família «Tapes» creada')->toContain('"type":"done"');

    $conversation = AssistantConversation::query()->where('kind', 'carta')->firstOrFail();
    $reply = $conversation->messages()->where('role', 'assistant')->firstOrFail();

    expect(Category::query()->where('name->ca', 'Tapes')->exists())->toBeTrue()
        ->and($reply->content)->toContain('He creat la família Tapes.')->toContain('Hi afegeixo productes?')
        ->and($reply->meta['options'])->toBe(['Sí, braves i croquetes', 'Està bé així'])
        ->and($reply->meta['previews'][0]['type'])->toBe('family')
        ->and($reply->prompt_tokens)->toBe(220);

    // Help conversations and carta conversations are listed separately.
    $this->actingAs($admin)->getJson(route('assistant.index'))->assertJsonPath('conversations', []);
    $this->actingAs($admin)->getJson(route('assistant.index', ['kind' => 'carta']))->assertJsonPath('conversations.0.id', $conversation->id);
});

test('the microphone audio is transcribed with elevenlabs', function () {
    config(['services.elevenlabs.key' => 'xi-test']);
    Http::preventStrayRequests();
    Http::fake(['api.elevenlabs.io/*' => Http::response(['text' => ' Crea el menú de dilluns ', 'language_code' => 'cat'])]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('assistant.transcribe'), ['audio' => UploadedFile::fake()->create('dictat.webm', 40, 'audio/webm')])
        ->assertOk()
        ->assertJsonPath('text', 'Crea el menú de dilluns');

    Http::assertSent(fn ($request) => $request->hasHeader('xi-api-key', 'xi-test') && str_ends_with($request->url(), '/speech-to-text'));
});

test('without an elevenlabs key the microphone reports it is not configured', function () {
    config(['services.elevenlabs.key' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('assistant.transcribe'), ['audio' => UploadedFile::fake()->create('dictat.webm', 40, 'audio/webm')], ['Accept' => 'application/json'])
        ->assertStatus(503);

    $this->actingAs($user)->getJson(route('assistant.index'))->assertJsonPath('voice', false);
});

test('the old carta amb ia address opens the assistant', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/gestion/carta-ia')->assertRedirect('/gestion/asistente');
    $this->actingAs($admin)->get(route('admin.assistant'))->assertOk();
});

test('the live microphone gets a single-use token without exposing the key', function () {
    config(['services.elevenlabs.key' => 'xi-secret']);
    Http::preventStrayRequests();
    Http::fake(['api.elevenlabs.io/v1/single-use-token/realtime_scribe' => Http::response(['token' => 'sutkn_123'])]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('assistant.voice-session'))
        ->assertOk()
        ->assertJsonPath('token', 'sutkn_123')
        ->assertJsonPath('model', 'scribe_v2_realtime');

    expect($response->json('url'))->toStartWith('wss://')
        ->and((string) $response->getContent())->not->toContain('xi-secret');
    Http::assertSent(fn ($request) => $request->hasHeader('xi-api-key', 'xi-secret') && $request->method() === 'POST');
});

test('the assistant prepares a menu for printing in the requested languages', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'en' => ['name' => 'Kids menu', 'includes' => null, 'sections' => [['name' => 'Dish', 'items' => ['Macaroni']]]],
    ])]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5]])]);
    $admin = User::factory()->admin()->create();
    $menu = SetMenu::query()->create(['name' => ['ca' => 'Menú infantil', 'es' => 'Menú infantil'], 'price' => 900, 'vat_rate' => 10, 'schedule_type' => 'always', 'active' => true, 'sort' => 1]);
    $menu->sections()->create(['name' => ['ca' => 'Plat', 'es' => 'Plato'], 'choices' => 1, 'sort' => 0])
        ->items()->create(['name' => ['ca' => 'Macarrons', 'es' => 'Macarrones'], 'sort' => 0]);

    $result = studioTools($admin)->run('print_set_menu', ['id' => $menu->id, 'languages' => ['ca', 'en', 'xx'], 'template' => 'kids']);

    expect($result['preview']['print']['languages'])->toBe(['ca', 'en'])
        ->and($result['preview']['template'])->toBe('kids')
        ->and($menu->refresh()->translations['locales']['en']['name'])->toBe('Kids menu');
});

test('the assistant also answers from the manual', function () {
    $admin = User::factory()->admin()->create();

    expect(app(MenuAgent::class)->systemPrompt($admin, 'ca'))
        ->toContain('MANUAL DE LA APLICACIÓN')
        ->toContain('print_set_menu');
});
