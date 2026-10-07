import { locale } from '@/i18n';
import type { OrderLine, VatRow } from './types';

export function formatMoney(cents: number, withSymbol = true): string {
    const value = (cents / 100).toLocaleString(locale.value === 'es' ? 'es-ES' : 'ca-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return withSymbol ? `${value} €` : value;
}

export function parseMoney(input: string | number | null | undefined): number {
    if (typeof input === 'number') {
        return Math.round(input * 100);
    }

    if (!input) {
        return 0;
    }

    const normalized = input.replace(/\s|€/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
    const value = Number.parseFloat(normalized);

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

/** PHP round(): half away from zero. */
export function roundHalfAway(value: number): number {
    return Math.sign(value) * Math.round(Math.abs(value));
}

export function unitTotal(line: Pick<OrderLine, 'unitPrice' | 'modifiers'>): number {
    return line.unitPrice + line.modifiers.reduce((sum, m) => sum + (m.priceDelta || 0), 0);
}

export function grossTotal(line: Pick<OrderLine, 'unitPrice' | 'modifiers' | 'quantity'>, quantity = line.quantity): number {
    return unitTotal(line) * quantity;
}

export function discountAmount(line: Pick<OrderLine, 'unitPrice' | 'modifiers' | 'quantity' | 'discountType' | 'discountValue'>, quantity = line.quantity): number {
    const gross = grossTotal(line, quantity);

    if (line.discountType === 'percent') {
        return roundHalfAway((gross * line.discountValue) / 100);
    }

    if (line.discountType === 'amount') {
        const perUnit = line.quantity > 0 ? line.discountValue / line.quantity : 0;

        return Math.min(gross, roundHalfAway(perUnit * quantity));
    }

    return 0;
}

export function netTotal(line: OrderLine, quantity = line.quantity): number {
    return line.voided ? 0 : grossTotal(line, quantity) - discountAmount(line, quantity);
}

/**
 * Mirror of App\Services\Tickets\TaxCalculator: VAT-inclusive prices grouped by rate,
 * surcharge spread proportionally, the last rate absorbs the rounding.
 */
export function computeTax(lines: { total: number; vatRate: number }[], surchargeRate: number) {
    const byRate = new Map<string, number>();

    for (const line of lines) {
        const key = line.vatRate.toFixed(2);
        byRate.set(key, (byRate.get(key) ?? 0) + line.total);
    }

    const keys = [...byRate.keys()].sort();
    const subtotal = keys.reduce((sum, key) => sum + (byRate.get(key) ?? 0), 0);
    const surcharge = roundHalfAway((subtotal * surchargeRate) / 100);

    let allocated = 0;
    const breakdown: VatRow[] = keys.map((key, index) => {
        const gross = byRate.get(key) ?? 0;
        const share = index === keys.length - 1 ? surcharge - allocated : subtotal > 0 ? roundHalfAway((surcharge * gross) / subtotal) : 0;
        allocated += share;
        const total = gross + share;
        const rate = Number.parseFloat(key);
        const base = roundHalfAway(total / (1 + rate / 100));

        return { rate, base, vat: total - base, total };
    });

    return { subtotal, surchargeAmount: surcharge, total: subtotal + surcharge, vatBreakdown: breakdown };
}

export function formatRate(rate: number): string {
    return `${Number.isInteger(rate) ? rate : rate.toFixed(1)}%`;
}
