import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { api, HttpError, xsrfToken } from '@/lib/http';

export type AssistantContext = 'tpv' | 'admin';

export type Conversation = {
    id: number;
    title: string | null;
    updatedAt: string;
};

export type ChatMessage = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    createdAt: string;
};

export type ChatEvent =
    | { type: 'start'; conversation: Conversation; question: ChatMessage }
    | { type: 'delta'; text: string }
    | { type: 'done'; message: ChatMessage; conversation: Conversation; error: string | null }
    | { type: 'error'; message: string; discarded: 'conversation' | 'question' };

const BASE = '/asistente/api';

export function listConversations(): Promise<{ conversations: Conversation[]; configured: boolean; model: string }> {
    return api('GET', `${BASE}/conversaciones`);
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
    body: { message: string; conversationId: number | null; context: AssistantContext; locale: string },
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

export function renderMarkdown(text: string): string {
    const html = marked.parse(text, { async: false, gfm: true, breaks: true });

    return DOMPurify.sanitize(html);
}
