<?php

use App\Jobs\TranslateCarta;
use App\Models\AssistantMessage;
use App\Models\Carta;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Assistant\CartaTools;
use App\Services\Assistant\MenuTranslator;
use App\Services\Catalog\CartaDocument;
use App\Services\Catalog\CartaWriter;
use App\Services\Catalog\MenuDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function cartaTools(User $user): CartaTools
{
    return new CartaTools($user, app(CartaWriter::class));
}

/**
 * @param  array<string, mixed>  $args
 * @return array<string, mixed>
 */
function cartaToolCall(string $name, array $args, string $id = 'call_1'): array
{
    return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]];
}

function cartaProduct(string $ca, string $es, ?int $price): Product
{
    $family = Category::query()->firstOrCreate(['name->ca' => 'Tapes'], ['name' => ['ca' => 'Tapes', 'es' => 'Tapas'], 'color' => '#0f766e', 'active' => true, 'sort' => 1]);

    return Product::query()->create(['category_id' => $family->id, 'name' => ['ca' => $ca, 'es' => $es], 'price' => $price, 'vat_rate' => 10, 'allergens' => [], 'active' => true, 'sold_out' => false, 'sort' => 1]);
}

test('a carta takes the price of the pos product when none is given, and refuses to invent one', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $braves = cartaProduct('Patates braves', 'Patatas bravas', 550);
    cartaProduct('Calamars', 'Calamares', null);
    $tools = cartaTools($admin);

    $refused = $tools->run('save_carta', [
        'name_ca' => 'Carta de tapes', 'name_es' => 'Carta de tapas',
        'sections' => [['name_ca' => 'Tapes', 'name_es' => 'Tapas', 'items' => [
            ['name_ca' => 'Patates braves', 'name_es' => 'Patatas bravas'],
            ['name_ca' => 'Calamars', 'name_es' => 'Calamares'],
            ['name_ca' => 'Pop a la gallega', 'name_es' => 'Pulpo a la gallega'],
        ]]],
    ]);

    expect($refused['result']['error'])->toContain('«Calamars»')->toContain('«Pop a la gallega»')->not->toContain('braves')
        ->and(Carta::query()->count())->toBe(0);

    $saved = $tools->run('save_carta', [
        'name_ca' => 'Carta de tapes', 'name_es' => 'Carta de tapas', 'template' => 'christmas',
        'sections' => [['name_ca' => 'Tapes', 'name_es' => 'Tapas', 'items' => [
            ['name_ca' => 'patates BRAVES', 'name_es' => 'Patatas bravas'],
            ['name_ca' => 'Calamars', 'name_es' => 'Calamares', 'price' => 7.5],
            ['name_ca' => 'Pop a la gallega', 'name_es' => 'Pulpo a la gallega', 'price' => '12,9', 'description_ca' => 'Amb pebre vermell', 'description_es' => 'Con pimentón'],
        ]]],
    ]);

    $carta = Carta::query()->with('sections.items')->findOrFail($saved['result']['carta_id']);
    $items = $carta->sections[0]->items;

    expect($saved['result']['prices_from_catalog'])->toBe(['Patates braves (5,50 €)'])
        ->and($saved['preview']['type'])->toBe('carta')
        ->and($saved['preview']['sections'][0]['items'][0])->toMatchArray(['price' => 550, 'fromCatalog' => true])
        ->and($carta->template)->toBe('christmas')
        ->and($items[0]->product_id)->toBe($braves->id)
        ->and($items[0]->name)->toBeNull()
        ->and($items[0]->price)->toBeNull()
        ->and($items[1]->price)->toBe(750)
        ->and($items[2]->price)->toBe(1290)
        ->and($items[2]->description)->toBe(['ca' => 'Amb pebre vermell', 'es' => 'Con pimentón']);

    // The dish follows the product's price.
    $braves->update(['price' => 600]);
    expect($items[0]->fresh()?->effectivePrice())->toBe(600);

    Queue::assertPushed(TranslateCarta::class);
});

test('a carta is changed a dish at a time', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $tools = cartaTools($admin);
    $flam = cartaProduct('Flam', 'Flan', 400);
    $id = $tools->run('save_carta', [
        'name_ca' => 'Postres', 'name_es' => 'Postres',
        'sections' => [['name_ca' => 'Postres', 'name_es' => 'Postres', 'items' => [
            ['product_id' => $flam->id],
            ['name_ca' => 'Crema catalana', 'name_es' => 'Crema catalana', 'price' => 5],
            ['name_ca' => 'Gelat', 'name_es' => 'Helado', 'price' => 3.5],
        ]]],
    ])['result']['carta_id'];
    $carta = Carta::query()->with('sections.items')->findOrFail($id);
    $sectionId = $carta->sections[0]->id;
    [$flamItem, $crema, $gelat] = $carta->sections[0]->items->all();

    $edited = $tools->run('edit_carta', ['id' => $id, 'template' => 'kids', 'changes' => [
        ['action' => 'add_item', 'section_id' => $sectionId, 'name_ca' => 'Mel i mató', 'name_es' => 'Miel y requesón', 'price' => 4.5, 'position' => 1],
        ['action' => 'update_item', 'item_id' => $crema->id, 'price' => 5.5],
        ['action' => 'remove_item', 'item_id' => $gelat->id],
        ['action' => 'add_section', 'name_ca' => 'Cafès', 'name_es' => 'Cafés'],
        ['action' => 'add_item', 'section' => 'Cafés', 'name_ca' => 'Tallat', 'name_es' => 'Cortado', 'price' => 1.6],
    ]]);

    $carta = Carta::query()->with('sections.items')->findOrFail($id);

    expect($edited['action']['label'])->toContain('+ Mel i mató 4,50 €')->toContain('− Gelat')->toContain('Crema catalana 5,50 €')
        ->and($carta->template)->toBe('kids')
        ->and($carta->sections->pluck('name.ca')->all())->toBe(['Postres', 'Cafès'])
        ->and($carta->sections[0]->items->map(fn ($i) => $i->displayName()['ca'])->all())->toBe(['Mel i mató', 'Flam', 'Crema catalana'])
        ->and($carta->sections[0]->items[1]->id)->toBe($flamItem->id)
        ->and($carta->sections[0]->items[2]->price)->toBe(550)
        ->and($carta->sections[1]->items[0]->price)->toBe(160);

    // Unknown dish without a price: nothing changes and the model is told to ask.
    $refused = $tools->run('edit_carta', ['id' => $id, 'changes' => [['action' => 'add_item', 'section_id' => $sectionId, 'name_ca' => 'Pastís', 'name_es' => 'Pastel']]]);
    expect($refused['result']['error'])->toContain('«Pastís»')
        ->and($carta->sections[0]->items()->count())->toBe(3);

    expect($tools->run('delete_carta', ['id' => $id, 'confirmed' => false])['result'])->toHaveKey('error');
    $tools->run('delete_carta', ['id' => $id, 'confirmed' => true]);
    expect(Carta::query()->find($id))->toBeNull();
});

test('a printed carta has its designs and languages, and a long one flows onto more sheets', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'en' => ['name' => 'Drinks', 'subtitle' => null, 'sections' => [['name' => 'Beers', 'items' => [['name' => 'Draught beer', 'description' => 'Small glass'], ['name' => 'Vermouth', 'description' => null]]]]],
    ])]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5]])]);
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $carta = new Carta;
    app(CartaWriter::class)->save($carta, [
        'name' => ['ca' => 'Begudes', 'es' => 'Bebidas'],
        'sections' => [['name' => ['ca' => 'Cerveses', 'es' => 'Cervezas'], 'items' => [
            ['name' => ['ca' => 'Canya', 'es' => 'Caña'], 'description' => ['ca' => 'Got petit', 'es' => 'Vaso pequeño'], 'price' => 220],
            ['name' => ['ca' => 'Vermut', 'es' => 'Vermut'], 'price' => 300],
        ]]],
    ]);

    $response = $this->actingAs($admin)->get(route('admin.cartas.pdf', ['carta' => $carta, 'template' => 'halloween', 'langs' => 'ca,en']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(preg_match_all('#/Type\s*/Page[^s]#', (string) $response->getContent()))->toBe(2)
        ->and($carta->refresh()->translations['locales']['en']['sections'][0]['items'][0]['name'])->toBe('Draught beer');

    $this->actingAs($admin)->get(route('admin.cartas.preview', ['carta' => $carta, 'lang' => 'en']))
        ->assertOk()->assertSee('Draught beer')->assertSee('Small glass')->assertSee('€2.20');

    $document = app(CartaDocument::class);
    $long = ['name' => 'Vins', 'subtitle' => null, 'sections' => array_fill(0, 6, ['name' => 'Negres', 'items' => array_fill(0, 14, ['name' => 'Vi negre de la casa criança', 'description' => 'Ull de llebre, Penedès', 'price' => '18,00 €'])])];

    expect(count($document->paginate($long, app(MenuDocument::class)->sizes($document->density($long)), 'classic', true)))->toBeGreaterThan(1);
});

test('cartas are managed from the back office and a dish needs a price of its own or from its product', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create();
    $vermut = cartaProduct('Vermut', 'Vermut', 300);
    $payload = [
        'name' => ['ca' => 'Vermuts', 'es' => 'Vermuts'],
        'subtitle' => ['ca' => 'Dissabte i diumenge', 'es' => 'Sábado y domingo'],
        'active' => true,
        'sections' => [['name' => ['ca' => 'Vermut', 'es' => 'Vermut'], 'items' => [['productId' => $vermut->id, 'name' => null, 'price' => null]]]],
    ];

    $this->actingAs($staff)->get(route('admin.cartas'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.cartas'))->assertOk();
    $this->actingAs($admin)->post(route('admin.cartas.store'), $payload)->assertSessionHasNoErrors();

    $payload['sections'][0]['items'][] = ['productId' => null, 'name' => ['ca' => 'Olives', 'es' => 'Aceitunas'], 'price' => null];
    $this->actingAs($admin)->post(route('admin.cartas.store'), $payload)->assertSessionHasErrors('sections.0.items.1.price');

    $carta = Carta::query()->firstOrFail();
    $this->actingAs($admin)->post(route('admin.cartas.duplicate', $carta))->assertRedirect();
    $this->actingAs($admin)->patchJson(route('admin.cartas.template', $carta), ['template' => 'easter'])->assertOk();

    expect(Carta::query()->count())->toBe(2)
        ->and($carta->refresh()->template)->toBe('easter');
});

test('carta translations keep the structure of the carta', function () {
    $translator = app(MenuTranslator::class);
    $source = [
        'name' => ['ca' => 'Postres', 'es' => 'Postres'],
        'subtitle' => null,
        'sections' => [['name' => ['ca' => 'Dolços', 'es' => 'Dulces'], 'items' => [['name' => ['ca' => 'Flam', 'es' => 'Flan'], 'description' => null]]]],
    ];

    $good = json_encode(['fr' => ['name' => 'Desserts', 'subtitle' => null, 'sections' => [['name' => 'Douceurs', 'items' => [['name' => 'Flan', 'description' => null]]]]]]);
    $bad = json_encode(['fr' => ['name' => 'Desserts', 'sections' => [['name' => 'Douceurs', 'items' => []]]]]);

    expect($translator->parse((string) $good, $source, ['fr'])['fr']['sections'][0]['items'][0]['name'])->toBe('Flan')
        ->and($translator->parse((string) $bad, $source, ['fr']))->toBe([]);
});

test('in the chat the assistant asks for a price it does not know, without answer buttons', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['openrouter.ai/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [cartaToolCall('save_carta', ['name_ca' => 'Postres', 'name_es' => 'Postres', 'sections' => [['name_ca' => 'Postres', 'name_es' => 'Postres', 'items' => [['name_ca' => 'Pastís de formatge', 'name_es' => 'Tarta de queso']]]]])]]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]])
        ->push(['choices' => [['message' => ['content' => null, 'tool_calls' => [cartaToolCall('ask_user', ['question' => 'A quin preu poso el pastís de formatge?'], 'call_2')]]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10]]),
    ]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('assistant.chat'), ['message' => 'Fes una carta de postres amb pastís de formatge', 'kind' => 'carta', 'context' => 'admin', 'locale' => 'ca'])
        ->assertOk()
        ->streamedContent();

    $reply = AssistantMessage::query()->where('role', 'assistant')->latest('id')->firstOrFail();

    expect(Carta::query()->count())->toBe(0)
        ->and($reply->content)->toContain('A quin preu poso el pastís de formatge?')
        ->and($reply->meta['options'] ?? [])->toBe([]);

    Http::assertSent(fn ($request) => collect($request->data()['messages'] ?? [])->contains(fn ($m) => $m['role'] === 'tool' && str_contains((string) $m['content'], 'No sé el precio de: «Pastís de formatge»')));
});
