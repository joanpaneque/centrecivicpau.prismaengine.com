<script setup lang="ts">
import { BookOpen, Check, CircleCheck, History, Loader2, Mic, MessageSquarePlus, Pencil, Send, Sparkles, Square, Trash2, Wand2, WifiOff, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import type { ChatEvent, ChatMessage, Conversation, OrderPreview, StudioAction } from '@/assistant/api';
import { ask, deleteConversation, listConversations, loadConversation, loadManual, renderMarkdown } from '@/assistant/api';
import ReportCard from '@/components/assistant/ReportCard.vue';
import StudioPreview from '@/components/assistant/StudioPreview.vue';
import VoiceButton from '@/components/assistant/VoiceButton.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { locale, t, tl } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { HttpError } from '@/lib/http';

/**
 * The administrators' assistant: talk or type ("crea'm el menú de dilluns", "posa les braves
 * a 5 €", "com tanco la caixa?") and it answers or does it, shows the result right in the
 * chat (with the printable menu) and offers big answer buttons. While speaking, the words
 * appear live.
 */
const props = withDefaults(defineProps<{ context: 'tpv' | 'admin'; userName?: string | null; admin?: boolean }>(), { userName: null, admin: true });
/** Each finished reply, so the POS can apply the orders it carries. */
const emit = defineEmits<{ reply: [message: ChatMessage] }>();
defineSlots<{ order?: (props: { order: OrderPreview }) => unknown }>();

const conversations = ref<Conversation[]>([]);
const activeId = ref<number | null>(null);
const messages = ref<ChatMessage[]>([]);
const configured = ref(true);
const voice = ref(true);
const loadingConversation = ref(false);
const working = ref(false);
const liveStatus = ref<string | null>(null);
const liveActions = ref<StudioAction[]>([]);
const recording = ref(false);
const liveText = ref('');
const manualOpen = ref(false);
const manualHtml = ref('');
const manualLoading = ref(false);
const input = ref('');
const historyOpen = ref(false);
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const scroller = ref<HTMLElement | null>(null);
const textarea = ref<HTMLTextAreaElement | null>(null);
let controller: AbortController | null = null;
let localId = -1;

const suggestions = computed(() => tl(props.admin ? 'studio.suggestions' : 'studio.staffSuggestions'));
const firstName = computed(() => (props.userName ?? '').split(' ')[0]);
const lastAssistant = computed(() => [...messages.value].reverse().find((m) => m.role === 'assistant') ?? null);
const options = computed(() => (!working.value && lastAssistant.value === messages.value[messages.value.length - 1] ? (lastAssistant.value?.meta?.options ?? []) : []));
const canSend = computed(() => online.value && configured.value && !working.value && input.value.trim().length > 0);

function updateOnline(): void {
    online.value = navigator.onLine;
}

onMounted(async () => {
    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);

    try {
        const response = await listConversations('carta');
        conversations.value = response.conversations;
        configured.value = response.configured;
        voice.value = response.voice;
    } catch {
        online.value = navigator.onLine;
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('online', updateOnline);
    window.removeEventListener('offline', updateOnline);
    controller?.abort();
});

function stop(): void {
    controller?.abort();
}

async function openManual(): Promise<void> {
    manualOpen.value = true;

    if (manualHtml.value) {
        return;
    }

    manualLoading.value = true;

    try {
        manualHtml.value = renderMarkdown((await loadManual()).manual);
    } catch (e) {
        manualOpen.value = false;
        toast.error(e instanceof HttpError ? e.message : t('assistant.offline'));
    } finally {
        manualLoading.value = false;
    }
}

function onRecording(active: boolean): void {
    recording.value = active;
    liveText.value = '';
    scrollToBottom();
}

function onLive(text: string): void {
    liveText.value = text;
    scrollToBottom();
}

function newChat(): void {
    if (working.value) {
        return;
    }

    activeId.value = null;
    messages.value = [];
    historyOpen.value = false;
}

async function openConversation(id: number): Promise<void> {
    if (working.value) {
        return;
    }

    historyOpen.value = false;
    activeId.value = id;
    messages.value = [];
    loadingConversation.value = true;

    try {
        const response = await loadConversation(id);

        if (activeId.value === id) {
            messages.value = response.messages;
            scrollToBottom();
        }
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('assistant.offline'));
    } finally {
        loadingConversation.value = false;
    }
}

async function remove(conversation: Conversation): Promise<void> {
    if (!confirm(t('assistant.deleteConfirm'))) {
        return;
    }

    try {
        await deleteConversation(conversation.id);
        conversations.value = conversations.value.filter((c) => c.id !== conversation.id);

        if (activeId.value === conversation.id) {
            newChat();
        }
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('assistant.offline'));
    }
}

function handleEvent(event: ChatEvent, optimisticId: number): void {
    switch (event.type) {
        case 'start':
            activeId.value = event.conversation.id;
            conversations.value = [event.conversation, ...conversations.value.filter((c) => c.id !== event.conversation.id)];
            messages.value = messages.value.map((m) => (m.id === optimisticId ? event.question : m));
            break;
        case 'status':
            liveStatus.value = event.status;
            break;
        case 'action':
            liveActions.value.push(event.action);
            liveStatus.value = null;
            scrollToBottom();
            break;
        case 'done':
            messages.value.push(event.message);
            emit('reply', event.message);
            conversations.value = [event.conversation, ...conversations.value.filter((c) => c.id !== event.conversation.id)];
            break;
        case 'error':
            throw new HttpError(500, { message: event.message, discarded: event.discarded });
    }
}

async function send(text = input.value): Promise<void> {
    const message = text.trim();

    if (!message || working.value || !online.value || !configured.value) {
        return;
    }

    const optimisticId = localId--;
    const startedIn = activeId.value;
    messages.value.push({ id: optimisticId, role: 'user', content: message, createdAt: new Date().toISOString() });
    input.value = '';
    resizeTextarea();
    working.value = true;
    liveStatus.value = null;
    liveActions.value = [];
    scrollToBottom();
    controller = new AbortController();

    try {
        await ask({ message, conversationId: startedIn, context: props.context, locale: locale.value, kind: 'carta' }, (event) => handleEvent(event, optimisticId), controller.signal);
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') {
            toast.info(t('assistant.stopped'));
        } else {
            messages.value = messages.value.filter((m) => m.id !== optimisticId);
            input.value = message;
            const body = e instanceof HttpError ? (e.body as { discarded?: string } | null) : null;

            if (body?.discarded === 'conversation' && startedIn === null) {
                conversations.value = conversations.value.filter((c) => c.id !== activeId.value);
                activeId.value = null;
            }

            if (e instanceof HttpError && e.status === 503) {
                configured.value = false;
            }

            toast.error(e instanceof HttpError ? e.message : t('assistant.error'));
        }
    } finally {
        working.value = false;
        liveStatus.value = null;
        liveActions.value = [];
        controller = null;
        scrollToBottom();
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void send();
    }
}

function resizeTextarea(): void {
    nextTick(() => {
        const el = textarea.value;

        if (el) {
            el.style.height = 'auto';
            el.style.height = `${Math.min(el.scrollHeight, 140)}px`;
        }
    });
}

function scrollToBottom(): void {
    nextTick(() => {
        const el = scroller.value;

        if (el) {
            el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
        }
    });
}

watch(() => messages.value.length, scrollToBottom);
</script>

<template>
    <div class="relative flex h-full min-h-0 flex-col overflow-hidden bg-slate-50 select-text dark:bg-slate-950">
        <!-- Header -->
        <header class="flex h-14 shrink-0 items-center gap-2 border-b bg-white px-2 dark:bg-slate-900">
            <Button variant="ghost" size="icon" class="size-11" :aria-label="t('assistant.history')" @click="historyOpen = true"><History class="size-5" /></Button>
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-[#00056a] to-violet-600 text-white"><Wand2 class="size-4" /></span>
                <h2 class="truncate font-semibold">{{ t('studio.title') }}</h2>
            </div>
            <Button variant="ghost" size="icon" class="size-11" :aria-label="t('assistant.manual')" @click="openManual"><BookOpen class="size-5" /></Button>
            <Button variant="outline" class="h-11 gap-1.5" :disabled="working" @click="newChat"><MessageSquarePlus class="size-5" /> <span class="max-sm:hidden">{{ t('assistant.newChat') }}</span></Button>
        </header>

        <div v-if="!online" class="flex items-center gap-2 border-b bg-amber-50 px-4 py-2 text-sm text-amber-900"><WifiOff class="size-4" /> {{ t('assistant.offline') }}</div>
        <div v-else-if="!configured" class="border-b bg-red-50 px-4 py-2 text-sm text-red-800">{{ t('assistant.notConfigured') }}</div>

        <!-- Messages -->
        <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto">
            <div v-if="loadingConversation" class="flex justify-center py-16"><Loader2 class="size-6 animate-spin text-slate-400" /></div>

            <div v-else-if="!messages.length && !recording" class="mx-auto flex max-w-xl flex-col items-center px-4 pt-8 pb-4 text-center">
                <div class="mb-4 flex size-16 items-center justify-center rounded-3xl bg-gradient-to-br from-[#00056a] to-violet-600 text-white shadow-lg"><Sparkles class="size-8" /></div>
                <h3 class="text-xl font-bold">{{ firstName ? t('studio.welcome', { name: firstName }) : t('studio.title') }}</h3>
                <p class="mt-1 text-sm text-slate-500">{{ t('studio.subtitle') }}</p>
                <div class="mt-6 grid w-full gap-2">
                    <button
                        v-for="suggestion in suggestions"
                        :key="suggestion"
                        type="button"
                        class="min-h-14 rounded-2xl border-2 bg-white px-4 py-3 text-left text-[15px] font-medium shadow-sm transition active:scale-[0.98] disabled:opacity-50 dark:bg-slate-900"
                        :disabled="!online || !configured || working"
                        @click="send(suggestion)"
                    >
                        {{ suggestion }}
                    </button>
                </div>
            </div>

            <div v-else class="mx-auto flex max-w-2xl flex-col gap-4 px-3 py-4">
                <template v-for="message in messages" :key="message.id">
                    <div v-if="message.role === 'user'" class="flex justify-end">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-[#00056a] px-4 py-2.5 text-[15px] whitespace-pre-wrap text-white">{{ message.content }}</div>
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <ul v-if="message.meta?.actions?.length" class="flex flex-col gap-1.5">
                            <li v-for="(action, i) in message.meta.actions" :key="i" class="flex items-start gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                                <CircleCheck class="mt-0.5 size-4 shrink-0" :class="action.kind === 'deleted' ? 'text-red-600' : 'text-emerald-600'" /> {{ action.label }}
                            </li>
                        </ul>
                        <template v-for="preview in message.meta?.previews ?? []" :key="`${preview.type}${preview.id}`">
                            <ReportCard v-if="preview.type === 'report'" :report="preview" />
                            <slot v-else-if="preview.type === 'order'" name="order" :order="preview" />
                            <StudioPreview v-else :preview="preview" :latest="message.id === lastAssistant?.id && preview.type === 'set_menu'" />
                        </template>
                        <div class="flex gap-2.5">
                            <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-200"><Sparkles class="size-4" /></div>
                            <!-- eslint-disable-next-line vue/no-v-html -->
                            <div class="assistant-md min-w-0 flex-1 rounded-2xl rounded-tl-md bg-white px-4 py-2.5 shadow-sm dark:bg-slate-900" v-html="renderMarkdown(message.content)" />
                        </div>
                    </div>
                </template>

                <!-- What is being said, live -->
                <div v-if="recording" class="flex justify-end">
                    <div class="max-w-[85%] rounded-2xl rounded-br-md border-2 border-dashed border-[#00056a]/40 bg-[#00056a]/5 px-4 py-2.5 text-[15px] text-[#00056a] dark:text-blue-200">
                        <span v-if="liveText">{{ liveText }}</span>
                        <span v-else class="flex items-center gap-2 text-slate-500"><Mic class="size-4 animate-pulse text-red-600" /> {{ t('assistant.voiceListening') }}</span>
                    </div>
                </div>

                <!-- Live progress while the assistant works -->
                <div v-if="working" class="flex flex-col gap-1.5">
                    <div v-for="(action, i) in liveActions" :key="i" class="flex items-start gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                        <Check class="mt-0.5 size-4 shrink-0 text-emerald-600" /> {{ action.label }}
                    </div>
                    <div class="flex items-center gap-2 px-1 py-1 text-sm text-slate-500">
                        <Loader2 class="size-4 animate-spin" /> {{ liveStatus ? t(`studio.status.${liveStatus}`) : t('studio.working') }}
                        <button type="button" class="ml-auto flex h-9 items-center gap-1.5 rounded-lg border bg-white px-3 text-sm font-medium text-slate-700 dark:bg-slate-900 dark:text-slate-200" @click="stop">
                            <Square class="size-3.5 fill-current" /> {{ t('assistant.stop') }}
                        </button>
                    </div>
                </div>

                <!-- Big answer buttons -->
                <div v-if="options.length" class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="option in options"
                        :key="option"
                        type="button"
                        class="min-h-14 rounded-2xl border-2 border-[#00056a]/20 bg-white px-4 py-3 text-[15px] font-semibold text-[#00056a] shadow-sm transition active:scale-[0.98] active:bg-[#00056a] active:text-white disabled:opacity-50 dark:bg-slate-900 dark:text-blue-200"
                        :disabled="!online || working"
                        @click="send(option)"
                    >
                        {{ option }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Composer: text field and the big microphone -->
        <form class="shrink-0 border-t bg-white px-3 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:bg-slate-900" @submit.prevent="send()">
            <div v-if="!recording" class="mx-auto flex max-w-2xl items-end gap-2 rounded-2xl border bg-slate-50 p-1.5 focus-within:border-[#00056a]/50 dark:bg-slate-800">
                <textarea
                    ref="textarea"
                    v-model="input"
                    rows="1"
                    maxlength="4000"
                    class="max-h-36 min-h-11 flex-1 resize-none bg-transparent px-2 py-2.5 text-base outline-none placeholder:text-slate-400"
                    :placeholder="t('studio.placeholder')"
                    :disabled="!online || !configured"
                    @input="resizeTextarea"
                    @keydown="onKeydown"
                />
                <Button type="submit" class="size-11 shrink-0 rounded-xl bg-[#00056a] hover:bg-[#00056a]/90" :disabled="!canSend" :aria-label="t('assistant.send')">
                    <Send class="size-5" />
                </Button>
            </div>
            <div class="mt-2 flex justify-center">
                <VoiceButton v-if="voice" size="lg" :disabled="!online || !configured || working" @text="send" @recording="onRecording" @live="onLive" />
                <p v-else class="py-1 text-center text-xs text-slate-500">{{ t('assistant.voiceNotConfigured') }}</p>
            </div>
        </form>

        <Dialog v-model:open="manualOpen">
            <DialogContent class="flex h-[88vh] max-w-4xl flex-col gap-0 p-0 sm:max-w-4xl">
                <DialogHeader class="border-b px-6 py-4">
                    <DialogTitle class="flex items-center gap-2"><BookOpen class="size-5" /> {{ t('assistant.manualTitle') }}</DialogTitle>
                </DialogHeader>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div v-if="manualLoading" class="flex justify-center py-16"><Loader2 class="size-6 animate-spin text-slate-400" /></div>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div v-else class="assistant-md" v-html="manualHtml" />
                </div>
            </DialogContent>
        </Dialog>

        <!-- History drawer -->
        <div v-if="historyOpen" class="absolute inset-0 z-20 bg-black/30" @click="historyOpen = false" />
        <aside class="absolute inset-y-0 left-0 z-30 flex w-80 max-w-[85%] flex-col bg-white shadow-xl transition-transform dark:bg-slate-900" :class="historyOpen ? 'translate-x-0' : '-translate-x-full'">
            <div class="flex items-center gap-2 border-b p-3">
                <p class="flex-1 font-semibold">{{ t('assistant.history') }}</p>
                <Button variant="ghost" size="icon" class="size-11" @click="historyOpen = false"><X class="size-5" /></Button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-2">
                <p v-if="!conversations.length" class="px-2 py-6 text-center text-sm text-slate-500">{{ t('assistant.noHistory') }}</p>
                <div v-for="conversation in conversations" :key="conversation.id" class="flex items-center gap-1 rounded-xl" :class="conversation.id === activeId ? 'bg-[#00056a]/10' : ''">
                    <button type="button" class="min-w-0 flex-1 px-3 py-3 text-left" @click="openConversation(conversation.id)">
                        <span class="block truncate text-sm font-medium"><Pencil class="mr-1 inline size-3.5 text-slate-400" />{{ conversation.title || t('assistant.untitled') }}</span>
                        <span class="block text-xs text-slate-500">{{ formatDateTime(conversation.updatedAt) }}</span>
                    </button>
                    <button type="button" class="flex size-11 items-center justify-center rounded-lg text-slate-400 hover:text-red-600" :aria-label="t('assistant.delete')" @click="remove(conversation)">
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
        </aside>
    </div>
</template>
