<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dictation for the assistant's microphone button: ElevenLabs Speech-to-Text (Scribe).
 * The language is detected automatically (staff speak Catalan or Spanish).
 */
class SpeechToText
{
    public function configured(): bool
    {
        return filled(config('services.elevenlabs.key'));
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
