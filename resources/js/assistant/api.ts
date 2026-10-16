import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { api, HttpError, xsrfToken } from '@/lib/http';
import './assistant.css';

export type AssistantContext = 'tpv' | 'admin';

/** help: questions about the app. carta: Carta amb IA, the assistant changes the catalog and set menus. */
export type AssistantKind = 'help' | 'carta';

export type T9n = { ca: string; es: string };

export type StudioAction = { kind: 'created' | 'updated' | 'deleted'; label: string };

export type FamilyPreview = {
    type: 'family';
    id: number;
    name: T9n;
    color: string | null;
    photoUrl: string | null;
    active: boolean;
    products: { id: number; name: T9n; price: number | null; soldOut: boolean; active: boolean }[];
};

export type MenuPreview = {
    type: 'set_menu';
    id: number;
    name: T9n;
    includes: T9n | null;
    price: number;
    color: string | null;
    template: string;
    active: boolean;
    scheduleType: 'always' | 'weekdays' | 'dates';
    weekdays: number[];
    startsOn: string | null;
    endsOn: string | null;
    sections: { name: T9n; choices: number; items: { name: T9n; supplement: number }[] }[];
    /** Set when the assistant prepared a print: languages to preselect. */
    print?: { languages: string[] } | null;
};

export type StudioMeta = { actions?: StudioAction[]; previews?: (FamilyPreview | MenuPreview)[]; options?: string[] };

export type Conversation = {
    id: number;
    title: string | null;
    updatedAt: string;
};

export type ChatMessage = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    meta?: StudioMeta | null;
    createdAt: string;
};

export type ChatEvent =
    | { type: 'start'; conversation: Conversation; question: ChatMessage }
    | { type: 'delta'; text: string }
    | { type: 'status'; status: 'catalog' | 'menus' }
    | { type: 'action'; action: StudioAction }
    | { type: 'done'; message: ChatMessage; conversation: Conversation; error: string | null }
    | { type: 'error'; message: string; discarded: 'conversation' | 'question' };

const BASE = '/asistente/api';

export function listConversations(kind: AssistantKind = 'help'): Promise<{ conversations: Conversation[]; configured: boolean; voice: boolean; model: string }> {
    return api('GET', `${BASE}/conversaciones?kind=${kind}`);
}

export function loadConversation(id: number): Promise<{ conversation: Conversation; messages: ChatMessage[] }> {
    return api('GET', `${BASE}/conversaciones/${id}`);
}

export function renameConversation(id: number, title: string): Promise<{ conversation: Conversation }> {
    return api('PATCH', `${BASE}/conversaciones/${id}`, { title });
}

export function deleteConversation(id: number): Promise<unknown> {
    return api('DELETE', `${BASE}/conversaciones/${id}`);
}

export function loadManual(): Promise<{ manual: string }> {
    return api('GET', `${BASE}/manual`, undefined, 30000);
}

/**
 * Send a question and call `onEvent` for every newline-delimited JSON event of the streamed answer.
 */
export async function ask(
    body: { message: string; conversationId: number | null; context: AssistantContext; locale: string; kind?: AssistantKind },
    onEvent: (event: ChatEvent) => void,
    signal: AbortSignal,
): Promise<void> {
    const response = await fetch(`${BASE}/chat`, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/x-ndjson, application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok || !response.body) {
        let data: unknown = null;

        try {
            data = await response.json();
        } catch {
            data = null;
        }

        throw new HttpError(response.status, data);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    const flush = (line: string) => {
        if (line.trim()) {
            onEvent(JSON.parse(line) as ChatEvent);
        }
    };

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });
        let newline = buffer.indexOf('\n');

        while (newline !== -1) {
            flush(buffer.slice(0, newline));
            buffer = buffer.slice(newline + 1);
            newline = buffer.indexOf('\n');
        }
    }

    flush(buffer + decoder.decode());
}

/** Send a voice recording and get the dictated text back (ElevenLabs on the server). */
export async function transcribe(audio: Blob): Promise<string> {
    const extension = audio.type.includes('mp4') ? 'm4a' : audio.type.includes('ogg') ? 'ogg' : 'webm';
    const form = new FormData();
    form.append('audio', audio, `dictat.${extension}`);

    const response = await fetch(`${BASE}/transcribe`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: form,
    });
    let data: { text?: string; message?: string } | null = null;

    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (!response.ok || !data?.text) {
        throw new HttpError(response.status, data);
    }

    return data.text;
}

export function renderMarkdown(text: string): string {
    const html = marked.parse(text, { async: false, gfm: true, breaks: true });

    return DOMPurify.sanitize(html);
}
