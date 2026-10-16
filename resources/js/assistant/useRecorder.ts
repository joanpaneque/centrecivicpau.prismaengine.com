import { onBeforeUnmount, ref } from 'vue';
import { transcribe } from './api';

export type RecorderState = 'idle' | 'recording' | 'transcribing';

const MAX_SECONDS = 120;
const MIME_TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];

export class RecorderError extends Error {
    constructor(
        public reason: 'unsupported' | 'denied' | 'transcribe',
        message: string = reason,
    ) {
        super(message);
    }
}

/**
 * Tap to talk: records from the microphone, shows the sound level, and on stop sends the
 * audio to be transcribed. Needs HTTPS (or localhost) for the browser to allow the mic.
 */
export function useRecorder() {
    const state = ref<RecorderState>('idle');
    const seconds = ref(0);
    const level = ref(0);

    let recorder: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let chunks: Blob[] = [];
    let timer: ReturnType<typeof setInterval> | null = null;
    let frame = 0;
    let audioContext: AudioContext | null = null;
    let finished: ((blob: Blob | null) => void) | null = null;

    function supported(): boolean {
        return typeof window !== 'undefined' && !!navigator.mediaDevices?.getUserMedia && typeof MediaRecorder !== 'undefined';
    }

    function cleanup(): void {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }

        cancelAnimationFrame(frame);
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        void audioContext?.close().catch(() => undefined);
        audioContext = null;
        level.value = 0;
    }

    function meter(source: MediaStream): void {
        try {
            audioContext = new AudioContext();
            const analyser = audioContext.createAnalyser();
            analyser.fftSize = 512;
            audioContext.createMediaStreamSource(source).connect(analyser);
            const data = new Uint8Array(analyser.fftSize);

            const tick = () => {
                analyser.getByteTimeDomainData(data);
                let sum = 0;

                for (const value of data) {
                    const v = (value - 128) / 128;
                    sum += v * v;
                }

                level.value = Math.min(1, Math.sqrt(sum / data.length) * 4);
                frame = requestAnimationFrame(tick);
            };

            tick();
        } catch {
            level.value = 0;
        }
    }

    async function start(): Promise<void> {
        if (state.value !== 'idle') {
            return;
        }

        if (!supported()) {
            throw new RecorderError('unsupported');
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
        } catch {
            throw new RecorderError('denied');
        }

        const mimeType = MIME_TYPES.find((type) => MediaRecorder.isTypeSupported(type));
        recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
        chunks = [];
        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                chunks.push(event.data);
            }
        };
        recorder.onstop = () => {
            const blob = chunks.length ? new Blob(chunks, { type: recorder?.mimeType || mimeType || 'audio/webm' }) : null;
            cleanup();
            finished?.(blob);
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
        meter(stream);
    }

    function halt(): Promise<Blob | null> {
        return new Promise((resolve) => {
            if (!recorder || recorder.state === 'inactive') {
                cleanup();
                resolve(null);

                return;
            }

            finished = resolve;
            recorder.stop();
        });
    }

    /** Stop and transcribe. Resolves with the text ('' if nothing was recorded). */
    async function stop(): Promise<string> {
        if (state.value !== 'recording') {
            return '';
        }

        state.value = 'transcribing';
        const blob = await halt();

        try {
            if (!blob || blob.size < 1200) {
                return '';
            }

            return await transcribe(blob);
        } catch (e) {
            throw new RecorderError('transcribe', e instanceof Error ? e.message : 'transcribe');
        } finally {
            state.value = 'idle';
        }
    }

    async function cancel(): Promise<void> {
        await halt();
        state.value = 'idle';
    }

    onBeforeUnmount(() => {
        void cancel();
    });

    return { state, seconds, level, supported, start, stop, cancel };
}
