<script setup lang="ts">
import { Loader2, Mic, Square, X } from '@lucide/vue';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';
import { RecorderError, useRecorder } from '@/assistant/useRecorder';
import { t } from '@/i18n';

/**
 * Microphone button: tap to talk, tap again to send; `live` carries the text as it is
 * spoken. `lg` is the big round button (with the time and a cancel button), `sm` fits
 * next to the send button.
 */
const props = withDefaults(defineProps<{ size?: 'lg' | 'sm'; disabled?: boolean }>(), { size: 'sm', disabled: false });
const emit = defineEmits<{ text: [text: string]; recording: [active: boolean]; live: [text: string] }>();

const recorder = useRecorder();
watch(recorder.live, (text) => emit('live', text));
const busy = computed(() => recorder.state.value !== 'idle');
const time = computed(() => `${Math.floor(recorder.seconds.value / 60)}:${String(recorder.seconds.value % 60).padStart(2, '0')}`);
const ring = computed(() => 1 + recorder.level.value * 0.6);

async function toggle(): Promise<void> {
    if (props.disabled && recorder.state.value === 'idle') {
        return;
    }

    if (recorder.state.value === 'recording') {
        emit('recording', false);

        try {
            const text = await recorder.stop();

            if (text.trim()) {
                emit('text', text.trim());
            } else {
                toast.info(t('assistant.voiceEmpty'));
            }
        } catch (e) {
            toast.error(e instanceof RecorderError && e.message !== 'transcribe' ? e.message : t('assistant.voiceError'));
        }

        return;
    }

    if (recorder.state.value !== 'idle') {
        return;
    }

    try {
        await recorder.start();
        emit('recording', true);
    } catch (e) {
        const reason = e instanceof RecorderError ? e.reason : 'unsupported';
        toast.error(reason === 'denied' ? t('assistant.micDenied') : t('assistant.micUnsupported'));
    }
}

async function cancel(): Promise<void> {
    await recorder.cancel();
    emit('recording', false);
}
</script>

<template>
    <div v-if="size === 'lg'" class="flex flex-col items-center gap-1.5">
        <div class="relative flex items-center justify-center">
            <span
                v-if="recorder.state.value === 'recording'"
                class="absolute size-20 rounded-full bg-red-500/25 transition-transform duration-75"
                :style="{ transform: `scale(${ring})` }"
            />
            <button
                type="button"
                class="relative flex size-20 items-center justify-center rounded-full text-white shadow-xl transition active:scale-95 disabled:opacity-40"
                :class="recorder.state.value === 'recording' ? 'bg-red-600' : 'bg-[#00056a]'"
                :disabled="(disabled && !busy) || recorder.state.value === 'transcribing'"
                :aria-label="recorder.state.value === 'recording' ? t('assistant.voiceStop') : t('assistant.voiceStart')"
                @click="toggle"
            >
                <Loader2 v-if="recorder.state.value === 'transcribing'" class="size-9 animate-spin" />
                <Square v-else-if="recorder.state.value === 'recording'" class="size-8 fill-current" />
                <Mic v-else class="size-9" />
            </button>
            <button
                v-if="recorder.state.value === 'recording'"
                type="button"
                class="absolute -right-16 flex size-12 items-center justify-center rounded-full bg-slate-200 text-slate-700 shadow active:scale-95 dark:bg-slate-700 dark:text-slate-100"
                :aria-label="t('common.cancel')"
                @click="cancel"
            >
                <X class="size-6" />
            </button>
        </div>
        <span class="text-xs font-medium text-slate-500" :class="recorder.state.value === 'recording' ? 'text-red-600 tabular-nums' : ''">
            {{ recorder.state.value === 'recording' ? `${time} · ${t('assistant.voiceTapToSend')}` : recorder.state.value === 'transcribing' ? t('assistant.voiceTranscribing') : t('assistant.voiceTapToTalk') }}
        </span>
    </div>

    <button
        v-else
        type="button"
        class="relative flex size-11 shrink-0 items-center justify-center rounded-xl transition active:scale-95 disabled:opacity-40"
        :class="recorder.state.value === 'recording' ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-100'"
        :disabled="(disabled && !busy) || recorder.state.value === 'transcribing'"
        :title="recorder.state.value === 'recording' ? t('assistant.voiceStop') : t('assistant.voiceStart')"
        @click="toggle"
    >
        <span
            v-if="recorder.state.value === 'recording'"
            class="absolute inset-0 rounded-xl bg-red-500/30 transition-transform duration-75"
            :style="{ transform: `scale(${ring})` }"
        />
        <Loader2 v-if="recorder.state.value === 'transcribing'" class="size-5 animate-spin" />
        <Square v-else-if="recorder.state.value === 'recording'" class="relative size-4 fill-current" />
        <Mic v-else class="size-5" />
    </button>
</template>
