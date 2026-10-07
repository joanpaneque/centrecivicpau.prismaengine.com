<?php

namespace App\Services\Assistant;

use App\Models\User;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chat assistant backed by OpenRouter. The whole user manual (docs/MANUAL.md) goes in the
 * system prompt, so answers are grounded on it and the provider can cache that prefix.
 */
class AssistantService
{
    public const HISTORY_LIMIT = 30;

    public const MESSAGE_MAX = 4000;

    public function configured(): bool
    {
        return filled(config('services.openrouter.key'));
    }

    public function model(): string
    {
        return (string) config('services.openrouter.model');
    }

    public function manualPath(): string
    {
        return base_path((string) config('services.openrouter.manual'));
    }

    public function manual(): string
    {
        $path = $this->manualPath();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function systemPrompt(User $user, string $locale, string $context): string
    {
        $language = $locale === 'ca' ? 'català' : 'castellano';
        $where = $context === 'tpv' ? 'el TPV (pantalla táctil de sala, caja o cocina)' : 'la Gestión (back office web)';
        $role = match ($user->role->value) {
            'admin' => 'administrador (acceso total: TPV y Gestión)',
            'kitchen' => 'cocina (solo TPV: cocina y fichaje)',
            default => 'personal de sala (solo TPV, sin acceso a la Gestión ni a la edición del plano)',
        };
        $clock = now();
        $clock->locale($locale);
        $now = $clock->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm');

        return <<<PROMPT
        Eres el asistente de la aplicación TPV y gestión del bar-restaurante del Centre Cívic Pau.
        Ayudas al personal a usar la aplicación: explicas cómo hacer las cosas, dónde está cada opción y qué significa cada pantalla.

        Reglas:
        - Básate SOLO en el manual de la aplicación que tienes abajo. Si algo no aparece en el manual, di claramente que la aplicación no lo hace o que no lo sabes, y sugiere hablar con el administrador. Nunca inventes botones, pantallas ni funciones.
        - Responde en el idioma en el que te escriban (por defecto {$language}). Cuando nombres botones o pantallas, usa la etiqueta tal y como aparece en la aplicación en ese idioma.
        - Sé breve y práctico: pasos numerados para los procedimientos, negrita para los botones, y como mucho unas pocas frases de contexto. Usa Markdown.
        - Ten en cuenta el rol del usuario. Si pide algo que su rol no permite, explícale que lo tiene que hacer un administrador.
        - No tienes acceso a los datos reales (ventas, comandas, fichajes, clientes): si te los piden, explica dónde consultarlos en la aplicación.
        - Si la pregunta no tiene que ver con la aplicación ni con el trabajo en el restaurante, recuérdalo amablemente y reconduce la conversación.

        ===== MANUAL DE LA APLICACIÓN =====
        {$this->manual()}
        ===== FIN DEL MANUAL =====

        Contexto de esta conversación (puede cambiar; el manual de arriba no):
        - Usuario: {$user->name}, rol {$role}.
        - Está usando {$where}.
        - Fecha y hora actuales: {$now}.
        PROMPT;
    }

    /**
     * Stream the reply text as it arrives. The generator's return value holds token usage.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return Generator<int, string, mixed, array{prompt_tokens: int|null, completion_tokens: int|null}>
     */
    public function stream(string $system, array $messages): Generator
    {
        if (! $this->configured()) {
            throw new AssistantException(__('assistant.not_configured'));
        }

        $payload = [
            'model' => $this->model(),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'messages' => [['role' => 'system', 'content' => $system], ...$messages],
        ];

        if (filled(config('services.openrouter.reasoning'))) {
            $payload['reasoning'] = ['effort' => (string) config('services.openrouter.reasoning'), 'exclude' => true];
        }

        try {
            $response = Http::withToken((string) config('services.openrouter.key'))
                ->withHeaders(['HTTP-Referer' => (string) config('app.url'), 'X-Title' => (string) config('app.name')])
                ->withOptions(['stream' => true])
                ->connectTimeout(10)
                ->timeout(180)
                ->post(rtrim((string) config('services.openrouter.url'), '/').'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            Log::warning('OpenRouter connection failed', ['error' => $e->getMessage()]);

            throw new AssistantException(__('assistant.unavailable'));
        }

        if ($response->failed()) {
            Log::warning('OpenRouter request failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 1000)]);

            throw new AssistantException(match ($response->status()) {
                401, 403 => __('assistant.bad_key'),
                402 => __('assistant.no_credit'),
                429 => __('assistant.rate_limited'),
                default => __('assistant.unavailable'),
            });
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $usage = ['prompt_tokens' => null, 'completion_tokens' => null];

        while (! $body->eof()) {
            $buffer .= $body->read(2048);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    return $usage;
                }

                $chunk = json_decode($data, true);

                if (! is_array($chunk)) {
                    continue;
                }

                if (isset($chunk['error'])) {
                    Log::warning('OpenRouter stream error', ['error' => $chunk['error']]);

                    throw new AssistantException(__('assistant.unavailable'));
                }

                $text = $chunk['choices'][0]['delta']['content'] ?? null;

                if (is_string($text) && $text !== '') {
                    yield $text;
                }

                if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                    $usage = [
                        'prompt_tokens' => isset($chunk['usage']['prompt_tokens']) ? (int) $chunk['usage']['prompt_tokens'] : null,
                        'completion_tokens' => isset($chunk['usage']['completion_tokens']) ? (int) $chunk['usage']['completion_tokens'] : null,
                    ];
                }
            }
        }

        return $usage;
    }
}
