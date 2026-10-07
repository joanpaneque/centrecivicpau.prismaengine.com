<?php

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeOpenRouter(string $text = 'Ves a Caixa per cobrar.'): void
{
    config(['services.openrouter.key' => 'test-key']);
    Http::preventStrayRequests();

    Http::fake([
        'openrouter.ai/*' => Http::response(
            'data: '.json_encode(['choices' => [['delta' => ['content' => $text]]]], JSON_UNESCAPED_UNICODE)."\n\n".
            'data: '.json_encode(['choices' => [['delta' => ['content' => '']]], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8]])."\n\n".
            "data: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);
}

test('guests cannot open the assistant', function () {
    $this->getJson(route('assistant.index'))->assertUnauthorized();
    $this->postJson(route('assistant.chat'), ['message' => 'Hola'])->assertUnauthorized();
});

test('staff cannot open the management page but can use the assistant api', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.assistant'))->assertForbidden();
    $this->actingAs($user)->getJson(route('assistant.index'))
        ->assertOk()
        ->assertJsonPath('configured', false)
        ->assertJsonPath('conversations', []);
});

test('the assistant is unavailable until the openrouter key is set', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('assistant.chat'), ['message' => 'Com cobro una taula?'])
        ->assertStatus(503);
});

test('the manual endpoint returns the application handbook', function () {
    $user = User::factory()->create();

    $manual = $this->actingAs($user)->getJson(route('assistant.manual'))->assertOk()->json('manual');

    expect($manual)->toContain('Manual de la aplicación')->toContain('Sala')->toContain('Caixa');
});

test('a user can chat, keep history, rename and delete a conversation', function () {
    fakeOpenRouter('Per cobrar, obre Caixa.');
    $user = User::factory()->create();

    $chat = $this->actingAs($user)
        ->post(route('assistant.chat'), ['message' => 'Com cobro?', 'context' => 'tpv', 'locale' => 'ca']);

    $chat->assertOk();
    $body = $chat->streamedContent();
    expect($body)->toContain('"type":"start"')->toContain('"type":"delta"')->toContain('"type":"done"')->toContain('Per cobrar');

    $conversation = AssistantConversation::query()->where('user_id', $user->id)->firstOrFail();
    expect($conversation->title)->toContain('Com cobro')
        ->and($conversation->messages)->toHaveCount(2)
        ->and($conversation->messages->first()->role)->toBe(AssistantMessage::USER)
        ->and($conversation->messages->last()->content)->toBe('Per cobrar, obre Caixa.');

    $this->actingAs($user)
        ->getJson(route('assistant.show', $conversation))
        ->assertOk()
        ->assertJsonPath('messages.0.content', 'Com cobro?')
        ->assertJsonPath('messages.1.role', 'assistant');

    $this->actingAs($user)
        ->patchJson(route('assistant.update', $conversation), ['title' => 'Cobrar taules'])
        ->assertOk()
        ->assertJsonPath('conversation.title', 'Cobrar taules');

    $this->actingAs($user)->deleteJson(route('assistant.destroy', $conversation))->assertOk();
    expect(AssistantConversation::query()->find($conversation->id))->toBeNull();
});

test('a user cannot read someone elses conversation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $conversation = AssistantConversation::query()->create(['user_id' => $owner->id, 'title' => 'Privat']);

    $this->actingAs($other)->getJson(route('assistant.show', $conversation))->assertNotFound();
    $this->actingAs($other)->deleteJson(route('assistant.destroy', $conversation))->assertNotFound();
});

test('an empty model reply does not leave a dangling conversation', function () {
    config(['services.openrouter.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'openrouter.ai/*' => Http::response(
            "data: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $user = User::factory()->create();

    $body = $this->actingAs($user)
        ->post(route('assistant.chat'), ['message' => 'Hola'])
        ->assertOk()
        ->streamedContent();

    expect($body)->toContain('"type":"error"')
        ->and(AssistantConversation::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(AssistantMessage::query()->count())->toBe(0);
});
