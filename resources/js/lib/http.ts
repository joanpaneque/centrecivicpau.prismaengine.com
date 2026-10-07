export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(
        public status: number,
        public body: unknown,
    ) {
        super(typeof body === 'object' && body && 'message' in body ? String((body as { message: unknown }).message) : `HTTP ${status}`);
    }
}

export async function api<T = unknown>(method: string, url: string, body?: unknown, timeoutMs = 15000): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(body !== undefined && !(body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
        });

        const text = await response.text();
        let data: unknown = null;

        try {
            data = text ? JSON.parse(text) : null;
        } catch {
            data = text;
        }

        if (!response.ok) {
            throw new HttpError(response.status, data);
        }

        return data as T;
    } finally {
        clearTimeout(timer);
    }
}

export function isNetworkError(error: unknown): boolean {
    return !(error instanceof HttpError) || error.status === 502 || error.status === 503 || error.status === 504;
}
