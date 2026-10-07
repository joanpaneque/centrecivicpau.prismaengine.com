import { ref } from 'vue';
import ca from './ca';
import es from './es';

export type Locale = 'ca' | 'es';
export type Translatable = Partial<Record<Locale, string>> | string | null | undefined;

const dictionaries = { ca, es } as const;
const STORAGE_KEY = 'tpv-locale';

function initialLocale(): Locale {
    const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;

    if (stored === 'ca' || stored === 'es') {
        return stored;
    }

    const html = typeof document !== 'undefined' ? document.documentElement.lang : '';

    return html.startsWith('es') ? 'es' : 'ca';
}

export const locale = ref<Locale>(initialLocale());

function lookup(dict: unknown, key: string): unknown {
    return key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), dict);
}

export function t(key: string, params: Record<string, string | number> = {}): string {
    const value = lookup(dictionaries[locale.value], key) ?? lookup(dictionaries.ca, key);
    const text = typeof value === 'string' ? value : key;

    return text.replace(/\{(\w+)\}/g, (_, name: string) => (params[name] !== undefined ? String(params[name]) : `{${name}}`));
}

export function tl(key: string): string[] {
    const value = lookup(dictionaries[locale.value], key);

    return Array.isArray(value) ? (value as string[]) : [];
}

/**
 * Pick the current language from a {ca, es} value stored in the database.
 */
export function tr(value: Translatable): string {
    if (!value) {
        return '';
    }

    if (typeof value === 'string') {
        return value;
    }

    return value[locale.value] || value.ca || value.es || '';
}

export function applyLocale(value: string | null | undefined): void {
    if (value !== 'ca' && value !== 'es') {
        return;
    }

    locale.value = value;
    localStorage.setItem(STORAGE_KEY, value);
    document.documentElement.lang = value;
}

export async function setLocale(value: Locale): Promise<void> {
    applyLocale(value);

    try {
        await fetch('/idioma', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ locale: value }),
        });
    } catch {
        // Offline: the choice is kept locally and the server keeps its previous value.
    }
}

export function useI18n() {
    return { t, tl, tr, locale, setLocale };
}
