<?php

namespace App\Services\Assistant;

use App\Models\Category;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns the shorthand a waiter types for an invented product ("croq. jamon") into a proper
 * product name in Catalan and Spanish, and proposes the family it belongs to. Uses the same
 * OpenRouter account as the assistant; any failure just means "no suggestion".
 */
class ProductNameSuggester
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function configured(): bool
    {
        return $this->assistant->configured();
    }

    /**
     * @return array{ca: string, es: string, categoryId: int|null}|null
     */
    public function suggest(string $typed): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $families = $this->families();
        $list = $families->map(fn (string $name, int $id) => "{$id}: {$name}")->implode("\n");

        $system = <<<PROMPT
        Ets l'assistent del TPV d'un bar-restaurant de Catalunya. Un cambrer ha escrit ràpidament el nom d'un producte que no és a la carta, sovint abreujat o amb faltes.
        La teva feina:
        1. Endevinar quin producte vol dir i escriure'n el nom correcte i complet, tal com sortiria en una carta, en català ("ca") i en castellà ("es"). Primera lletra en majúscula, la resta com a la carta (no tot en majúscules). Expandeix abreviatures (croq. → croquetes, bocata → entrepà/bocadillo...). Si ja és correcte, torna'l igual (només traduït).
        2. Triar la família de la carta on encaixa millor, de la llista. Si cap encaixa, posa null.

        Famílies (id: nom):
        {$list}

        Respon NOMÉS amb un objecte JSON, sense res més: {"ca": "...", "es": "...", "categoryId": 12}
        PROMPT;

        $payload = [
            'model' => $this->assistant->model(),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $typed],
            ],
            'max_tokens' => 400,
        ];

        if (filled(config('services.openrouter.reasoning'))) {
            $payload['reasoning'] = ['effort' => 'low', 'exclude' => true];
        }

        try {
            $response = Http::withToken((string) config('services.openrouter.key'))
                ->withHeaders(['HTTP-Referer' => (string) config('app.url'), 'X-Title' => (string) config('app.name')])
                ->connectTimeout(5)
                ->timeout(20)
                ->post(rtrim((string) config('services.openrouter.url'), '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            Log::warning('Product name suggestion failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Product name suggestion failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            return null;
        }

        return $this->parse((string) ($response->json('choices.0.message.content') ?? ''), $families);
    }

    /**
     * @param  Collection<int, string>  $families
     * @return array{ca: string, es: string, categoryId: int|null}|null
     */
    public function parse(string $content, Collection $families): ?array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($content, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            return null;
        }

        $ca = is_string($data['ca'] ?? null) ? mb_substr(trim($data['ca']), 0, 80) : '';
        $es = is_string($data['es'] ?? null) ? mb_substr(trim($data['es']), 0, 80) : '';

        if ($ca === '' && $es === '') {
            return null;
        }

        $categoryId = is_numeric($data['categoryId'] ?? null) ? (int) $data['categoryId'] : null;

        return [
            'ca' => $ca !== '' ? $ca : $es,
            'es' => $es !== '' ? $es : $ca,
            'categoryId' => $categoryId !== null && $families->has($categoryId) ? $categoryId : null,
        ];
    }

    /**
     * Active families as "Parent › Child" so the model sees the whole tree.
     *
     * @return Collection<int, string>
     */
    private function families(): Collection
    {
        $categories = Category::query()->where('active', true)->orderBy('sort')->get();
        $byId = $categories->keyBy('id');

        return $categories->mapWithKeys(function (Category $category) use ($byId) {
            $name = $category->name['ca'] ?? $category->name['es'] ?? '';
            $parent = $category->parent_id ? $byId->get($category->parent_id) : null;

            return [$category->id => $parent ? ($parent->name['ca'] ?? '').' › '.$name : $name];
        });
    }
}
