import { reactive, watch } from 'vue';
import { t } from '@/i18n';
import { randomToken, uuid } from './crypto';
import { computeTax, discountAmount, grossTotal } from './money';
import { childLines, ticketViewFor } from './orders';
import { job, lineAmount, ticketDocument, ticketPrinterId, widthFor, zDocument } from './print';
import type { TicketView, ZSummary } from './print';
import { setKv, state } from './store';
import { enqueue } from './sync';
import type { Order, OrderLine, PrintDocument } from './types';

export type PaymentInput = { method: 'cash' | 'card'; amount: number; tendered?: number | null };
export type Selection = { line: OrderLine; quantity: number }[];

const VERIFACTU_QR = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR';
const SPLITS_KEY = 'tpv-splits';

/** Equal splits cannot be expressed as whole paid quantities, so progress is kept on the cashier. */
export const splits = reactive<Record<string, { parts: number; paidParts: number; paidAmount: number }>>(
    (() => {
        try {
            return JSON.parse(localStorage.getItem(SPLITS_KEY) ?? '{}');
        } catch {
            return {};
        }
    })(),
);

watch(splits, (value) => localStorage.setItem(SPLITS_KEY, JSON.stringify(value)), { deep: true });

export function nextTicketNumber(): number {
    const last = state.cashier?.series?.lastNumber ?? 0;

    return Math.max(last, (state.seriesNext ?? 1) - 1) + 1;
}

export function formatTicketNumber(code: string, number: number): string {
    return `${code}-${String(number).padStart(6, '0')}`;
}

export function verifactuUrl(fullNumber: string, issuedAt: string, total: number): string {
    const date = new Date(issuedAt);
    const fecha = `${String(date.getDate()).padStart(2, '0')}-${String(date.getMonth() + 1).padStart(2, '0')}-${date.getFullYear()}`;
    const params = new URLSearchParams({ nif: state.settings?.issuer_tax_id ?? '', numserie: fullNumber, fecha, importe: (total / 100).toFixed(2) });

    return `${VERIFACTU_QR}?${params.toString()}`;
}

export function surchargeRateFor(order: Order | null): number {
    const zone = order?.tableId ? state.zones[state.tables[order.tableId]?.zoneId] : null;

    return zone?.appliesTerraceSurcharge ? Number(state.settings?.terrace_surcharge_percent ?? 0) : 0;
}

export function pendingSelection(order: Order): Selection {
    return order.lines.filter((l) => !l.parentUuid && !l.voided && l.paidQuantity < l.quantity).map((line) => ({ line, quantity: line.quantity - line.paidQuantity }));
}

type TicketLineInput = {
    orderLineUuid: string | null;
    name: { ca: string; es: string };
    quantity: number;
    unitPrice: number;
    vatRate: number;
    discountAmount: number;
    total: number;
    modifiers: unknown[];
};

function ticketLines(order: Order, selection: Selection, factor = 1): TicketLineInput[] {
    return selection.map(({ line, quantity }) => {
        const children = childLines(order, line).filter((c) => !c.voided);
        const share = line.quantity ? quantity / line.quantity : 1;
        const childGross = children.reduce((sum, c) => sum + grossTotal(c), 0) * share;
        const childDiscount = children.reduce((sum, c) => sum + discountAmount(c), 0) * share;
        const gross = grossTotal(line, quantity) + childGross;
        const total = Math.round(lineAmount(line, order.lines, quantity) * factor);

        return {
            orderLineUuid: line.uuid,
            name: { ca: line.name.ca, es: line.name.es },
            quantity: Math.round(quantity * factor * 1000) / 1000,
            unitPrice: quantity ? Math.round(gross / quantity) : 0,
            vatRate: line.vatRate,
            discountAmount: Math.round((discountAmount(line, quantity) + childDiscount) * factor),
            total,
            modifiers: [...line.modifiers.map((m) => ({ name: m.name, priceDelta: m.priceDelta })), ...children.map((c) => ({ name: c.name, priceDelta: 0 }))],
        };
    });
}

export function selectionTotal(order: Order, selection: Selection, factor = 1): { subtotal: number; surchargeAmount: number; total: number } {
    const lines = ticketLines(order, selection, factor);
    const tax = computeTax(lines.map((l) => ({ total: l.total, vatRate: l.vatRate })), surchargeRateFor(order));

    return { subtotal: tax.subtotal, surchargeAmount: tax.surchargeAmount, total: tax.total };
}

/**
 * Issue a simplified invoice locally: number from this cashier's series, VAT breakdown,
 * Verifactu QR and public invoice-request QR, then queue it with its print job.
 */
export async function issueTicket(
    order: Order,
    selection: Selection,
    payments: PaymentInput[],
    opts: { factor?: number; partLabel?: string | null; closeOrder?: boolean; markPaid?: boolean; tip?: number } = {},
): Promise<{ fullNumber: string; document: PrintDocument; change: number; tip: number }> {
    const series = state.cashier?.series;
    const session = state.cashier?.session;

    if (!series || !session) {
        throw new Error('cashier_not_ready');
    }

    const factor = opts.factor ?? 1;
    const number = nextTicketNumber();
    const fullNumber = formatTicketNumber(series.code, number);
    const issuedAt = new Date().toISOString();
    const lines = ticketLines(order, selection, factor);
    const surchargeRate = surchargeRateFor(order);
    const tax = computeTax(lines.map((l) => ({ total: l.total, vatRate: l.vatRate })), surchargeRate);
    const publicToken = randomToken(16);
    const tableLabel = order.tableId ? (state.tables[order.tableId]?.label ?? null) : order.label;

    let remaining = tax.total;
    const paymentRows = payments.map((p, index) => {
        const isLast = index === payments.length - 1;
        const amount = p.method === 'card' ? p.amount : isLast ? remaining : Math.min(remaining, p.amount);
        remaining = Math.max(0, remaining - Math.min(remaining, amount));
        const tendered = p.method === 'cash' && p.tendered ? Math.max(p.tendered, amount) : null;

        return { uuid: uuid(), method: p.method, amount, tendered, change: tendered !== null ? tendered - amount : null };
    });

    const cardPaid = paymentRows.filter((p) => p.method === 'card').reduce((sum, p) => sum + p.amount, 0);
    const cashPaid = paymentRows.filter((p) => p.method === 'cash').reduce((sum, p) => sum + p.amount, 0);
    const tip = Math.max(0, opts.tip ?? cardPaid + cashPaid - tax.total);

    const base = ticketViewFor(order, selection);
    const view: TicketView = {
        ...base,
        fullNumber,
        issuedAt,
        lines: base.lines.map((l, i) => ({ ...l, quantity: lines[i]?.quantity ?? l.quantity, total: lines[i]?.total ?? l.total, discount: lines[i]?.discountAmount ?? l.discount })),
        surchargeRate,
        surchargeAmount: tax.surchargeAmount,
        total: tax.total,
        vatBreakdown: tax.vatBreakdown,
        payments: paymentRows,
        invoiceUrl: `${window.location.origin}/factura/${publicToken}`,
        verifactuUrl: verifactuUrl(fullNumber, issuedAt, tax.total),
        partLabel: opts.partLabel ?? null,
        tip: tip > 0 ? tip : null,
    };

    const printerId = ticketPrinterId();
    const document = ticketDocument(view, widthFor(printerId));
    const markPaid = opts.markPaid ?? true;

    await enqueue(
        'ticket.issue',
        {
            ticket: {
                uuid: uuid(),
                seriesCode: series.code,
                number,
                fullNumber,
                orderUuid: order.uuid,
                cashSessionUuid: session.uuid,
                tableLabel,
                waiterId: order.openedBy,
                issuedAt,
                surchargeRate,
                total: tax.total,
                discountTotal: lines.reduce((sum, l) => sum + l.discountAmount, 0),
                surchargeAmount: tax.surchargeAmount,
                vatBreakdown: tax.vatBreakdown,
                publicToken,
                lines,
                payments: paymentRows,
            },
            paidLines: markPaid ? selection.map(({ line, quantity }) => ({ lineUuid: line.uuid, quantity })) : [],
            closeOrder: !!opts.closeOrder,
        },
        [job('ticket', `${t('cashier.title')} · ${fullNumber}`, printerId, document)],
    );

    setKv('seriesNext', number + 1);

    return { fullNumber, document, change: paymentRows.reduce((sum, p) => sum + (p.change ?? 0), 0), tip };
}

export async function reprint(title: string, document: PrintDocument): Promise<void> {
    const printerId = ticketPrinterId();
    await enqueue('print.job', {}, [job('reprint', `${t('cashier.reprint')} · ${title}`, printerId, document)]);
}

export async function openSession(openingFloat: number): Promise<void> {
    await enqueue('cash.open', { sessionUuid: uuid(), openedAt: new Date().toISOString(), openingFloat });
}

/** Local mirror of App\Services\Cashier\CashSessionSummary from the tickets kept on this device. */
export function localSummary(): ZSummary {
    const cashier = state.cashier;
    const tickets = [...(cashier?.tickets ?? [])].sort((a, b) => a.number - b.number);
    const byMethod: Record<string, number> = { cash: 0, card: 0 };
    const vat = new Map<number, { rate: number; base: number; vat: number; total: number }>();

    for (const ticket of tickets) {
        for (const payment of ticket.payments) {
            byMethod[payment.method] = (byMethod[payment.method] ?? 0) + payment.amount;
        }

        for (const row of ticket.vatBreakdown) {
            const current = vat.get(row.rate) ?? { rate: row.rate, base: 0, vat: 0, total: 0 };
            current.base += row.base;
            current.vat += row.vat;
            current.total += row.total;
            vat.set(row.rate, current);
        }
    }

    const openedAt = cashier?.session?.openedAt ?? new Date().toISOString();
    const voids = Object.values(state.orders)
        .filter((o) => o.openedAt >= openedAt || (o.closedAt ?? '') >= openedAt)
        .flatMap((o) => o.lines.filter((l) => l.voided && !l.parentUuid).map((l) => grossTotal(l)));
    const opening = cashier?.session?.openingFloat ?? 0;

    return {
        tickets: tickets.length,
        total: tickets.reduce((sum, t) => sum + t.total, 0),
        by_method: byMethod,
        discounts: tickets.reduce((sum, t) => sum + t.discountTotal, 0),
        surcharge: tickets.reduce((sum, t) => sum + t.surchargeAmount, 0),
        voids: { count: voids.length, amount: voids.reduce((a, b) => a + b, 0) },
        vat_breakdown: [...vat.values()].sort((a, b) => a.rate - b.rate),
        opening_float: opening,
        expected_cash: opening + (byMethod.cash ?? 0),
        first_ticket: tickets[0]?.fullNumber ?? null,
        last_ticket: tickets[tickets.length - 1]?.fullNumber ?? null,
    };
}

export function reportDocument(partial: boolean, counted: number | null = null): PrintDocument {
    const summary = localSummary();
    const session = state.cashier?.session;

    return zDocument(summary, {
        zNumber: null,
        openedAt: session?.openedAt ?? new Date().toISOString(),
        closedAt: new Date().toISOString(),
        counted,
        difference: counted === null ? null : counted - summary.expected_cash,
        device: state.device?.name ?? '',
        partial,
    }, widthFor(ticketPrinterId()));
}

export async function closeSession(cashCount: Record<string, number>, countedCash: number, notes: string): Promise<PrintDocument> {
    const session = state.cashier?.session;

    if (!session) {
        throw new Error('no_session');
    }

    const document = reportDocument(false, countedCash);
    const printerId = ticketPrinterId();

    await enqueue('cash.close', { sessionUuid: session.uuid, closedAt: new Date().toISOString(), cashCount, countedCash, notes: notes || null }, [
        job('z_report', `${t('cashier.zReport')} · ${state.device?.name ?? ''}`, printerId, document),
    ]);

    return document;
}

export function orderLabel(order: Order): string {
    if (order.tableId && state.tables[order.tableId]) {
        return `${t('reservations.table')} ${state.tables[order.tableId].label}`;
    }

    return order.label || t('floor.quickSaleLabel');
}

export function waiterName(order: Order): string {
    return (order.openedBy && state.staff[order.openedBy]?.name) || '';
}