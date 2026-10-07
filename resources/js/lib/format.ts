import { locale } from '@/i18n';

export { formatMoney as money, parseMoney } from '@/pos/money';

function intlLocale(): string {
    return locale.value === 'es' ? 'es-ES' : 'ca-ES';
}

export function formatDate(value: string | Date | null | undefined, options: Intl.DateTimeFormatOptions = { day: '2-digit', month: '2-digit', year: 'numeric' }): string {
    if (!value) {
        return '—';
    }

    const date = typeof value === 'string' ? new Date(value.length === 10 ? `${value}T00:00:00` : value) : value;

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString(intlLocale(), options);
}

export function formatTime(value: string | Date | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = typeof value === 'string' ? new Date(value) : value;

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString(intlLocale(), { hour: '2-digit', minute: '2-digit' });
}

export function formatDateTime(value: string | Date | null | undefined): string {
    if (!value) {
        return '—';
    }

    return `${formatDate(value)} ${formatTime(value)}`;
}

export function formatMinutes(total: number | null | undefined): string {
    const minutes = Math.round(total ?? 0);
    const sign = minutes < 0 ? '-' : '';
    const abs = Math.abs(minutes);

    return `${sign}${Math.floor(abs / 60)}:${String(abs % 60).padStart(2, '0')}`;
}

export function todayIso(): string {
    return toIsoDate(new Date());
}

export function toIsoDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function addDays(iso: string, days: number): string {
    const date = new Date(`${iso}T00:00:00`);
    date.setDate(date.getDate() + days);

    return toIsoDate(date);
}

/** Local datetime string usable as a datetime-local input value. */
export function toLocalInput(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return `${toIsoDate(date)}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

export function toTrans(value: unknown): { ca: string; es: string } {
    if (typeof value === 'string') {
        return { ca: value, es: value };
    }

    const record = (value ?? {}) as Record<string, string | null | undefined>;

    return { ca: record.ca ?? '', es: record.es ?? '' };
}

export function centsToInput(cents: number | null | undefined): string {
    return cents === null || cents === undefined ? '' : (cents / 100).toFixed(2);
}
