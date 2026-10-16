<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dictation for the assistant's microphone button: ElevenLabs Speech-to-Text (Scribe).
 * The browser streams the audio to Scribe Realtime with a single-use token (live text while
 * speaking); a whole recording can also be transcribed at once as a fallback. The language
 * is detected automatically (staff speak Catalan or Spanish).
 */
class SpeechToText
{
    public function configured(): bool
    {
        return filled(config('services.elevenlabs.key'));
    }

    /**
     * Connection details for the browser's realtime session. The token is single-use and
     * expires in 15 minutes, so the API key never reaches the browser.
     *
     * @return array{token: string, url: string, model: string}
     *
     * @throws AssistantException
     */
    public function realtimeSession(): array
    {
        if (! $this->configured()) {
            throw new AssistantException(__('assistant.voice_not_configured'));
        }

        try {
            $response = Http::withHeaders(['xi-api-key' => (string) config('services.elevenlabs.key')])
                ->connectTimeout(5)
                ->timeout(10)
                ->post(rtrim((string) config('services.elevenlabs.url'), '/').'/single-use-token/realtime_scribe');
        } catch (ConnectionException $e) {
            Log::warning('ElevenLabs token request failed', ['error' => $e->getMessage()]);

            throw new AssistantException(__('assistant.voice_unavailable'));
        }

        $token = $response->json('token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            Log::warning('ElevenLabs token request failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            throw new AssistantException(in_array($response->status(), [401, 403], true) ? __('assistant.voice_bad_key') : __('assistant.voice_unavailable'));
        }

        return [
            'token' => $token,
            'url' => (string) config('services.elevenlabs.realtime_url'),
            'model' => (string) config('services.elevenlabs.realtime_model'),
        ];
    }

    /**
     * @throws AssistantException
     */
    public function transcribe(UploadedFile $audio): string
    {
        if (! $this->configured()) {
            throw new AssistantException(__('assistant.voice_not_configured'));
        }

        try {
            $response = Http::withHeaders(['xi-api-key' => (string) config('services.elevenlabs.key')])
                ->connectTimeout(10)
                ->timeout(60)
                ->attach('file', (string) file_get_contents($audio->getRealPath()), $audio->getClientOriginalName() ?: 'audio.webm')
                ->post(rtrim((string) config('services.elevenlabs.url'), '/').'/speech-to-text', [
                    'model_id' => (string) config('services.elevenlabs.stt_model'),
                    'tag_audio_events' => 'false',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('ElevenLabs connection failed', ['error' => $e->getMessage()]);

            throw new AssistantException(__('assistant.voice_unavailable'));
        }

        if ($response->failed()) {
            Log::warning('ElevenLabs transcription failed', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            throw new AssistantException(match ($response->status()) {
                401, 403 => __('assistant.voice_bad_key'),
                default => __('assistant.voice_unavailable'),
            });
        }

        return trim((string) $response->json('text', ''));
    }
}
