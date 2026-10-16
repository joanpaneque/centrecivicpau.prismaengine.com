import { onBeforeUnmount, ref } from 'vue';
import { api } from '@/lib/http';
import { transcribe } from './api';

export type RecorderState = 'idle' | 'recording' | 'transcribing';

const MAX_SECONDS = 120;
const RATE = 16000;
const CHUNK = 1600; // 100 ms of 16 kHz audio per message
const MIME_TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
const ERRORS = ['error', 'auth_error', 'quota_exceeded', 'rate_limited', 'resource_exhausted', 'session_time_limit_exceeded', 'input_error', 'invalid_request', 'transcriber_error', 'unaccepted_terms'];

/** Taps the microphone audio inside the audio thread and hands it to the page. */
const TAP = `class Tap extends AudioWorkletProcessor {
    process(inputs) { const c = inputs[0] && inputs[0][0]; if (c) this.port.postMessage(c.slice(0)); return true; }
}
registerProcessor('pcm-tap', Tap);`;

export class RecorderError extends Error {
    constructor(
        public reason: 'unsupported' | 'denied' | 'transcribe',
        message: string = reason,
    ) {
        super(message);
    }
}

function toBase64(samples: Int16Array): string {
    const bytes = new Uint8Array(samples.buffer, samples.byteOffset, samples.byteLength);
    let binary = '';

    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }

    return btoa(binary);
}

/**
 * Tap to talk. While recording, the audio streams to ElevenLabs Scribe Realtime and `live`
 * shows the text as it is spoken. A normal recording runs alongside: if the live session
 * cannot start or returns nothing, that recording is transcribed when the user stops.
 * Needs HTTPS (or localhost) for the browser to allow the microphone.
 */
export function useRecorder() {
    const state = ref<RecorderState>('idle');
    const seconds = ref(0);
    const level = ref(0);
    const live = ref('');

    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let finished: ((blob: Blob | null) => void) | null = null;
    let timer: ReturnType<typeof setInterval> | null = null;
    let frame = 0;
    let context: AudioContext | null = null;
    let tap: AudioNode | null = null;

    // Realtime session
    let socket: WebSocket | null = null;
    let socketOpen = false;
    let realtimeFailed = false;
    let pending: string[] = [];
    let samples: number[] = [];
    let committed: string[] = [];
    let partial = '';
    let onCommit: (() => void) | null = null;

    function supported(): boolean {
        return typeof window !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && typeof MediaRecorder !== 'undefined';
    }

    function refreshLive(): void {
        live.value = [...committed, partial].filter(Boolean).join(' ').trim();
    }

    function send(final = false): void {
        if (!socket || realtimeFailed) {
            return;
        }

        const pcm = new Int16Array(samples.length);

        for (let i = 0; i < samples.length; i++) {
            const s = Math.max(-1, Math.min(1, samples[i]));
            pcm[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
        }

        samples = [];
        const message = JSON.stringify({ message_type: 'input_audio_chunk', audio_base_64: toBase64(pcm), commit: final, sample_rate: RATE });

        if (socketOpen) {
            socket.send(message);
        } else {
            pending.push(message);
        }
    }

    /** Downsample whatever rate the device records at to 16 kHz mono. */
    function feed(input: Float32Array, rate: number): void {
        const ratio = rate / RATE;

        for (let position = 0; position + ratio <= input.length; position += ratio) {
            const start = Math.floor(position);
            const end = Math.min(input.length, Math.floor(position + ratio));
            let sum = 0;

            for (let i = start; i < end; i++) {
                sum += input[i];
            }

            samples.push(end > start ? sum / (end - start) : input[start]);
        }

        if (samples.length >= CHUNK) {
            send();
        }
    }

    async function connectRealtime(): Promise<void> {
        try {
            const session = await api<{ token: string; url: string; model: string }>('POST', '/asistente/api/voice-session', undefined, 8000);
            const params = new URLSearchParams({ model_id: session.model, token: session.token, audio_format: 'pcm_16000', commit_strategy: 'vad' });
            socket = new WebSocket(`${session.url}?${params.toString()}`);
            socket.onopen = () => {
                socketOpen = true;
                pending.forEach((message) => socket?.send(message));
                pending = [];
            };
            socket.onmessage = (event) => {
                const message = JSON.parse(String(event.data)) as { message_type: string; text?: string };

                if (message.message_type === 'partial_transcript') {
                    partial = message.text ?? '';
                } else if (message.message_type === 'committed_transcript' || message.message_type === 'committed_transcript_with_timestamps') {
                    if (message.text?.trim()) {
                        committed.push(message.text.trim());
                    }

                    partial = '';
                    onCommit?.();
                } else if (ERRORS.includes(message.message_type)) {
                    realtimeFailed = true;
                }

                refreshLive();
            };
            socket.onerror = () => {
                realtimeFailed = true;
            };
            socket.onclose = () => {
                socketOpen = false;
                onCommit?.();
            };
        } catch {
            realtimeFailed = true;
        }
    }

    async function listen(source: MediaStream): Promise<void> {
        try {
            context = new AudioContext();
            // Created after the permission prompt, so some browsers (iOS) start it suspended.
            await context.resume().catch(() => undefined);
            const input = context.createMediaStreamSource(source);
            const mute = context.createGain();
            mute.gain.value = 0;
            mute.connect(context.destination);
            const analyser = context.createAnalyser();
            analyser.fftSize = 512;
            input.connect(analyser);
            const levels = new Uint8Array(analyser.fftSize);

            const tick = () => {
                analyser.getByteTimeDomainData(levels);
                let sum = 0;

                for (const value of levels) {
                    const v = (value - 128) / 128;
                    sum += v * v;
                }

                level.value = Math.min(1, Math.sqrt(sum / levels.length) * 4);
                frame = requestAnimationFrame(tick);
            };

            tick();

            const rate = context.sampleRate;

            if (context.audioWorklet) {
                const url = URL.createObjectURL(new Blob([TAP], { type: 'application/javascript' }));
                await context.audioWorklet.addModule(url);
                URL.revokeObjectURL(url);
                const node = new AudioWorkletNode(context, 'pcm-tap');
                node.port.onmessage = (event) => feed(event.data as Float32Array, rate);
                input.connect(node);
                // Pulled through a silent output so every browser keeps it running.
                node.connect(mute);
                tap = node;
            } else {
                const processor = context.createScriptProcessor(4096, 1, 1);
                processor.onaudioprocess = (event) => feed(event.inputBuffer.getChannelData(0), rate);
                input.connect(processor);
                processor.connect(mute);
                tap = processor;
            }
        } catch {
            realtimeFailed = true;
        }
    }

    function cleanup(): void {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }

        cancelAnimationFrame(frame);
        tap?.disconnect();
        tap = null;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        void context?.close().catch(() => undefined);
        context = null;
        level.value = 0;

        if (socket && socket.readyState <= WebSocket.OPEN) {
            socket.close();
        }

        socket = null;
        socketOpen = false;
        onCommit = null;
    }

    async function start(): Promise<void> {
        if (state.value !== 'idle') {
            return;
        }

        if (!supported()) {
            throw new RecorderError('unsupported');
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 } });
        } catch {
            throw new RecorderError('denied');
        }

        live.value = '';
        committed = [];
        partial = '';
        pending = [];
        samples = [];
        realtimeFailed = false;

        const mimeType = MIME_TYPES.find((type) => MediaRecorder.isTypeSupported(type));
        recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
        chunks = [];
        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                chunks.push(event.data);
            }
        };
        recorder.onstop = () => {
            finished?.(chunks.length ? new Blob(chunks, { type: recorder?.mimeType || mimeType || 'audio/webm' }) : null);
            finished = null;
        };
        recorder.start(250);

        state.value = 'recording';
        seconds.value = 0;
        timer = setInterval(() => {
            seconds.value++;

            if (seconds.value >= MAX_SECONDS) {
                void stop();
            }
        }, 1000);

        void connectRealtime();
        await listen(stream);
    }

    function stopRecording(): Promise<Blob | null> {
        return new Promise((resolve) => {
            if (!recorder || recorder.state === 'inactive') {
                resolve(null);

                return;
            }

            finished = resolve;
            recorder.stop();
        });
    }

    /** Last audio and a commit, then wait (briefly) for the final words. */
    function flushRealtime(): Promise<void> {
        return new Promise((resolve) => {
            if (!socket || realtimeFailed) {
                resolve();

                return;
            }

            const timeout = setTimeout(resolve, 2500);
            onCommit = () => {
                clearTimeout(timeout);
                resolve();
            };
            send(true);
        });
    }

    /** Stop and return the text ('' if nothing was understood). */
    async function stop(): Promise<string> {
        if (state.value !== 'recording') {
            return '';
        }

        state.value = 'transcribing';

        try {
            const [blob] = await Promise.all([stopRecording(), flushRealtime()]);
            const text = [...committed, partial].filter(Boolean).join(' ').trim();
            cleanup();

            if (text) {
                return text;
            }

            if (!blob || blob.size < 1200) {
                return '';
            }

            return await transcribe(blob);
        } catch (e) {
            throw new RecorderError('transcribe', e instanceof Error ? e.message : 'transcribe');
        } finally {
            cleanup();
            state.value = 'idle';
            live.value = '';
        }
    }

    async function cancel(): Promise<void> {
        await stopRecording();
        cleanup();
        live.value = '';
        state.value = 'idle';
    }

    onBeforeUnmount(() => {
        void cancel();
    });

    return { state, seconds, level, live, supported, start, stop, cancel };
}
