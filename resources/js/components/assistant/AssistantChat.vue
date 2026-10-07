<script setup lang="ts">
import { BookOpen, Bot, Check, Copy, History, Loader2, MessageSquarePlus, Pencil, Send, Sparkles, Square, Trash2, WifiOff, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import type { AssistantContext, ChatEvent, ChatMessage, Conversation } from '@/assistant/api';
import { ask, deleteConversation, listConversations, loadConversation, loadManual, renameConversation, renderMarkdown } from '@/assistant/api';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { locale, t, tl } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { HttpError } from '@/lib/http';

const props = defineProps<{ context: AssistantContext; userName?: string | null }>();

const conversations = ref<Conversation[]>([]);
const activeId = ref<number | null>(null);
const messages = ref<ChatMessage[]>([]);
const configured = ref(true);
const loadingList = ref(true);
const loadingConversation = ref(false);
const streaming = ref(false);
const streamingText = ref('');
const input = ref('');
const historyOpen = ref(false);
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const renamingId = ref<number | null>(null);
const renameText = ref('');
const copiedId = ref<number | null>(null);
const manualOpen = ref(false);
const manualHtml = ref('');
const manualLoading = ref(false);
const scroller = ref<HTMLElement | null>(null);
const textarea = ref<HTMLTextAreaElement | null>(null);
let controller: AbortController | null = null;
let pinned = true;
let localId = -1;

const active = computed(() => conversations.value.find((c) => c.id === activeId.value) ?? null);
const suggestions = computed(() => tl(`assistant.suggestions.${props.context}`));
const firstName = computed(() => (props.userName ?? '').split(' ')[0]);
const canSend = computed(() => online.value && configured.value && !streaming.value && input.value.trim().length > 0);

function updateOnline(): void {
    online.value = navigator.onLine;
}

onMounted(async () => {
    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);
    await refreshList();
    textarea.value?.focus();
});

onBeforeUnmount(() => {
    window.removeEventListener('online', updateOnline);
    window.removeEventListener('offline', updateOnline);
    controller?.abort();
});

async function refreshList(): Promise<void> {
    loadingList.value = true;

    try {
        const response = await listConversations();
        conversations.value = response.conversations;
        configured.value = response.configured;
    } catch {
        online.value = navigator.onLine;
    } finally {
        loadingList.value = false;
    }
}

function upsertConversation(conversation: Conversation): void {
    conversations.value = [conversation, ...conversations.value.filter((c) => c.id !== conversation.id)];
}

function newChat(): void {
    if (streaming.value) {
        return;
    }

    activeId.value = null;
    messages.value = [];
    historyOpen.value = false;
    nextTick(() => textarea.value?.focus());
}

async function openConversation(id: number): Promise<void> {
    if (streaming.value || renamingId.value === id) {
        return;
    }

    historyOpen.value = false;

    if (id === activeId.value) {
        return;
    }

    activeId.value = id;
    messages.value = [];
    loadingConversation.value = true;

    try {
        const response = await loadConversation(id);

        if (activeId.value === id) {
            messages.value = response.messages;
            pinned = true;
            scrollToBottom();
        }
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('assistant.offline'));
    } finally {
        loadingConversation.value = false;
    }
}

function startRename(conversation: Conversation): void {
    renamingId.value = conversation.id;
    renameText.value = conversation.title ?? '';
}

async function saveRename(): Promise<void> {
    const id = renamingId.value;
    const title = renameText.value.trim();
    renamingId.value = null;

    if (!id || !title) {
        return;
    }

    try {
        const response = await renameConversation(id, title);
        conversations.value = conversations.value.map((c) => (c.id === id ? response.conversation : c));
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('assistant.offline'));
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
            activeId.value = null;
            messages.value = [];
        }
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('assistant.offline'));
    }
}

function handleEvent(event: ChatEvent, optimisticId: number): void {
    switch (event.type) {
        case 'start':
            activeId.value = event.conversation.id;
            upsertConversation(event.conversation);
            messages.value = messages.value.map((m) => (m.id === optimisticId ? event.question : m));
            break;
        case 'delta':
            streamingText.value += event.text;
            break;
        case 'done':
            messages.value.push(event.message);
            streamingText.value = '';
            upsertConversation(event.conversation);

            if (event.error) {
                toast.error(event.error);
            }

            break;
        case 'error':
            throw new HttpError(500, { message: event.message, discarded: event.discarded });
    }
}

async function send(text = input.value): Promise<void> {
    const message = text.trim();

    if (!message || streaming.value || !online.value || !configured.value) {
        return;
    }

    const optimisticId = localId--;
    const startedIn = activeId.value;
    messages.value.push({ id: optimisticId, role: 'user', content: message, createdAt: new Date().toISOString() });
    input.value = '';
    resizeTextarea();
    streaming.value = true;
    streamingText.value = '';
    pinned = true;
    scrollToBottom();
    controller = new AbortController();

    try {
        await ask({ message, conversationId: startedIn, context: props.context, locale: locale.value }, (event) => handleEvent(event, optimisticId), controller.signal);
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') {
            if (streamingText.value.trim()) {
                messages.value.push({ id: localId--, role: 'assistant', content: streamingText.value, createdAt: new Date().toISOString() });
            }

            toast.info(t('assistant.stopped'));
        } else {
            messages.value = messages.value.filter((m) => m.id !== optimisticId && m.content !== message);
            input.value = message;
            const body = e instanceof HttpError ? (e.body as { discarded?: string } | null) : null;

            if (body?.discarded === 'conversation' || (startedIn === null && e instanceof HttpError && e.status !== 500)) {
                if (activeId.value !== null && startedIn === null) {
                    conversations.value = conversations.value.filter((c) => c.id !== activeId.value);
                }

                activeId.value = startedIn;
            }

            if (e instanceof HttpError && e.status === 503) {
                configured.value = false;
            }

            toast.error(e instanceof HttpError ? e.message : t('assistant.error'));
        }
    } finally {
        streaming.value = false;
        streamingText.value = '';
        controller = null;
        nextTick(() => textarea.value?.focus());
    }
}

function stop(): void {
    controller?.abort();
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
            el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
        }
    });
}

function onScroll(): void {
    const el = scroller.value;

    if (el) {
        pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    }
}

function scrollToBottom(): void {
    nextTick(() => {
        const el = scroller.value;

        if (el && pinned) {
            el.scrollTop = el.scrollHeight;
        }
    });
}

watch([() => messages.value.length, streamingText], scrollToBottom);

async function copy(message: ChatMessage): Promise<void> {
    try {
        await navigator.clipboard.writeText(message.content);
    } catch {
        const area = document.createElement('textarea');
        area.value = message.content;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }

    copiedId.value = message.id;
    setTimeout(() => (copiedId.value = copiedId.value === message.id ? null : copiedId.value), 1500);
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
</script>

<template>
    <div class="relative flex h-full min-h-0 select-text overflow-hidden rounded-xl border bg-background">
        <div v-if="historyOpen" class="absolute inset-0 z-20 bg-black/30 md:hidden" @click="historyOpen = false" />
        <aside
            class="absolute inset-y-0 left-0 z-30 flex w-72 shrink-0 flex-col border-r bg-slate-50 transition-transform md:static md:z-auto md:translate-x-0 dark:bg-slate-900"
            :class="historyOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex items-center gap-2 border-b p-3">
                <Button class="h-11 flex-1 bg-[#00056a] hover:bg-[#00056a]/90" :disabled="streaming" @click="newChat">
                    <MessageSquarePlus class="size-4" /> {{ t('assistant.newChat') }}
                </Button>
                <Button variant="ghost" size="icon" class="size-11 md:hidden" @click="historyOpen = false"><X class="size-5" /></Button>
            </div>
            <p class="px-4 pt-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">{{ t('assistant.history') }}</p>
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                <div v-if="loadingList" class="flex justify-center py-8"><Loader2 class="size-5 animate-spin text-muted-foreground" /></div>
                <p v-else-if="conversations.length === 0" class="px-2 py-6 text-center text-sm text-muted-foreground">{{ t('assistant.noHistory') }}</p>
                <div
                    v-for="conversation in conversations"
                    :key="conversation.id"
                    class="group flex items-center gap-1 rounded-lg pr-1"
                    :class="conversation.id === activeId ? 'bg-[#00056a]/10' : 'hover:bg-slate-200/60 dark:hover:bg-slate-800'"
                >
                    <form v-if="renamingId === conversation.id" class="flex-1 p-1" @submit.prevent="saveRename">
                        <Input v-model="renameText" maxlength="120" class="h-10" autofocus @keydown.esc="renamingId = null" @blur="saveRename" />
                    </form>
                    <button v-else type="button" class="min-w-0 flex-1 px-3 py-2.5 text-left" @click="openConversation(conversation.id)">
                        <span class="block truncate text-sm font-medium" :class="conversation.id === activeId ? 'text-[#00056a] dark:text-white' : ''">
                            {{ conversation.title || t('assistant.untitled') }}
                        </span>
                        <span class="block text-xs text-muted-foreground">{{ formatDateTime(conversation.updatedAt) }}</span>
                    </button>
                    <template v-if="renamingId !== conversation.id">
                        <button
                            type="button"
                            class="flex size-9 items-center justify-center rounded-md text-muted-foreground opacity-100 hover:bg-white hover:text-foreground md:opacity-0 md:group-hover:opacity-100 dark:hover:bg-slate-700"
                            :title="t('assistant.rename')"
                            @click="startRename(conversation)"
                        >
                            <Pencil class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="flex size-9 items-center justify-center rounded-md text-muted-foreground opacity-100 hover:bg-white hover:text-red-600 md:opacity-0 md:group-hover:opacity-100 dark:hover:bg-slate-700"
                            :title="t('assistant.delete')"
                            @click="remove(conversation)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </template>
                </div>
            </div>
        </aside>

        <section class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 shrink-0 items-center gap-2 border-b px-3">
                <Button variant="ghost" size="icon" class="size-11 md:hidden" @click="historyOpen = true"><History class="size-5" /></Button>
                <Sparkles class="hidden size-5 shrink-0 text-[#00056a] md:block dark:text-white" />
                <h2 class="min-w-0 flex-1 truncate font-semibold">{{ active?.title || t('assistant.title') }}</h2>
                <Button variant="outline" class="h-10" @click="openManual"><BookOpen class="size-4" /> <span class="hidden sm:inline">{{ t('assistant.manual') }}</span></Button>
            </header>

            <div v-if="!online" class="flex items-center gap-2 border-b bg-amber-50 px-4 py-2 text-sm text-amber-900"><WifiOff class="size-4" /> {{ t('assistant.offline') }}</div>
            <div v-else-if="!configured" class="border-b bg-red-50 px-4 py-2 text-sm text-red-800">{{ t('assistant.notConfigured') }}</div>

            <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto" @scroll.passive="onScroll">
                <div v-if="loadingConversation" class="flex justify-center py-16"><Loader2 class="size-6 animate-spin text-muted-foreground" /></div>

                <div v-else-if="messages.length === 0 && !streaming" class="mx-auto flex max-w-2xl flex-col items-center px-4 py-10 text-center sm:py-16">
                    <div class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-[#00056a] text-white shadow-lg"><Bot class="size-7" /></div>
                    <h3 class="text-xl font-semibold">{{ firstName ? t('assistant.welcome', { name: firstName }) : t('assistant.title') }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">{{ t('assistant.subtitle') }}</p>
                    <div class="mt-8 grid w-full gap-2 sm:grid-cols-2">
                        <button
                            v-for="suggestion in suggestions"
                            :key="suggestion"
                            type="button"
                            class="min-h-14 rounded-xl border bg-white px-4 py-3 text-left text-sm shadow-xs transition hover:border-[#00056a]/40 hover:bg-[#00056a]/5 disabled:opacity-50 dark:bg-slate-900"
                            :disabled="!online || !configured"
                            @click="send(suggestion)"
                        >
                            {{ suggestion }}
                        </button>
                    </div>
                </div>

                <div v-else class="mx-auto flex max-w-3xl flex-col gap-5 px-3 py-6 sm:px-6">
                    <template v-for="message in messages" :key="message.id">
                        <div v-if="message.role === 'user'" class="flex justify-end">
                            <div class="max-w-[85%] rounded-2xl rounded-br-md bg-[#00056a] px-4 py-2.5 text-[15px] whitespace-pre-wrap text-white">{{ message.content }}</div>
                        </div>
                        <div v-else class="group flex gap-3">
                            <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-[#00056a]/10 text-[#00056a] dark:text-white"><Bot class="size-4" /></div>
                            <div class="min-w-0 flex-1">
                                <!-- eslint-disable-next-line vue/no-v-html -->
                                <div class="assistant-md" v-html="renderMarkdown(message.content)" />
                                <button
                                    type="button"
                                    class="mt-1 flex h-8 items-center gap-1.5 rounded-md px-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                                    @click="copy(message)"
                                >
                                    <component :is="copiedId === message.id ? Check : Copy" class="size-3.5" />
                                    {{ copiedId === message.id ? t('assistant.copied') : t('assistant.copy') }}
                                </button>
                            </div>
                        </div>
                    </template>

                    <div v-if="streaming" class="flex gap-3">
                        <div class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-[#00056a]/10 text-[#00056a] dark:text-white"><Bot class="size-4" /></div>
                        <div class="min-w-0 flex-1">
                            <!-- eslint-disable-next-line vue/no-v-html -->
                            <div v-if="streamingText" class="assistant-md" v-html="renderMarkdown(streamingText)" />
                            <p v-else class="flex items-center gap-2 py-1 text-sm text-muted-foreground"><Loader2 class="size-4 animate-spin" /> {{ t('assistant.thinking') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <form class="shrink-0 border-t bg-background p-3" @submit.prevent="send()">
                <div class="mx-auto flex max-w-3xl items-end gap-2 rounded-2xl border bg-white p-2 shadow-sm focus-within:border-[#00056a]/50 dark:bg-slate-900">
                    <textarea
                        ref="textarea"
                        v-model="input"
                        rows="1"
                        maxlength="4000"
                        class="max-h-40 min-h-11 flex-1 resize-none bg-transparent px-2 py-2.5 text-[15px] outline-none placeholder:text-muted-foreground"
                        :placeholder="t('assistant.placeholder')"
                        :disabled="!online || !configured"
                        @input="resizeTextarea"
                        @keydown="onKeydown"
                    />
                    <Button v-if="streaming" type="button" variant="outline" class="size-11 shrink-0 rounded-xl" :title="t('assistant.stop')" @click="stop">
                        <Square class="size-4 fill-current" />
                    </Button>
                    <Button v-else type="submit" class="size-11 shrink-0 rounded-xl bg-[#00056a] hover:bg-[#00056a]/90" :disabled="!canSend" :title="t('assistant.send')">
                        <Send class="size-4" />
                    </Button>
                </div>
                <p class="mx-auto mt-1.5 max-w-3xl text-center text-[11px] text-muted-foreground">{{ t('assistant.disclaimer') }}</p>
            </form>
        </section>

        <Dialog v-model:open="manualOpen">
            <DialogContent class="flex h-[88vh] max-w-4xl flex-col gap-0 p-0 sm:max-w-4xl">
                <DialogHeader class="border-b px-6 py-4">
                    <DialogTitle class="flex items-center gap-2"><BookOpen class="size-5" /> {{ t('assistant.manualTitle') }}</DialogTitle>
                </DialogHeader>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div v-if="manualLoading" class="flex justify-center py-16"><Loader2 class="size-6 animate-spin text-muted-foreground" /></div>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div v-else class="assistant-md" v-html="manualHtml" />
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>

<style>
.assistant-md {
    font-size: 15px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.assistant-md > * + * {
    margin-top: 0.75em;
}
.assistant-md h1 {
    font-size: 1.5em;
    font-weight: 700;
    margin-top: 1.2em;
}
.assistant-md h2 {
    font-size: 1.25em;
    font-weight: 700;
    margin-top: 1.4em;
    padding-bottom: 0.2em;
    border-bottom: 1px solid var(--border);
}
.assistant-md h3 {
    font-size: 1.08em;
    font-weight: 600;
    margin-top: 1.2em;
}
.assistant-md h4 {
    font-weight: 600;
}
.assistant-md ul {
    list-style: disc;
    padding-left: 1.4em;
}
.assistant-md ol {
    list-style: decimal;
    padding-left: 1.5em;
}
.assistant-md li + li {
    margin-top: 0.25em;
}
.assistant-md li > ul,
.assistant-md li > ol {
    margin-top: 0.25em;
}
.assistant-md strong {
    font-weight: 600;
}
.assistant-md a {
    color: #00056a;
    text-decoration: underline;
}
.assistant-md code {
    font-size: 0.9em;
    background: rgb(0 5 106 / 0.07);
    border-radius: 4px;
    padding: 0.1em 0.35em;
}
.assistant-md pre {
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 8px;
    padding: 0.8em 1em;
    overflow-x: auto;
    font-size: 0.85em;
}
.assistant-md pre code {
    background: none;
    padding: 0;
    color: inherit;
}
.assistant-md blockquote {
    border-left: 3px solid rgb(0 5 106 / 0.3);
    padding-left: 0.9em;
    color: var(--muted-foreground);
}
.assistant-md table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92em;
    display: block;
    overflow-x: auto;
}
.assistant-md th,
.assistant-md td {
    border: 1px solid var(--border);
    padding: 0.35em 0.6em;
    text-align: left;
}
.assistant-md th {
    background: rgb(0 5 106 / 0.05);
    font-weight: 600;
}
.assistant-md hr {
    border-color: var(--border);
}
</style>
