import { t, tr } from '@/i18n';
import { uuid } from './crypto';
import { formatMoney, formatRate, netTotal } from './money';
import { state } from './store';
import type { Order, OrderLine, PrintDocument, PrintJobPayload, PrintLine, VatRow } from './types';

export const DEFAULT_WIDTH = 48;

function pad(left: string, right: string, width: number): string {
    const space = width - left.length - right.length;

    return space >= 1 ? left + ' '.repeat(space) + right : `${left.slice(0, Math.max(0, width - right.length - 1))} ${right}`;
}

export function rowText(left: string, right: string, width = DEFAULT_WIDTH): string {
    return pad(left, right, width);
}

function time(iso: string): string {
    return new Date(iso).toLocaleString('ca-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function staffName(id: number | null | undefined): string {
    return (id && state.staff[id]?.name) || state.me?.name || '';
}

function tableLabel(order: Order): string {
    if (order.tableId && state.tables[order.tableId]) {
        return state.tables[order.tableId].label;
    }

    return order.label || t('floor.quickSale');
}

export function printersFor(destinationId: number | null): number[] {
    const destination = destinationId ? state.destinations[destinationId] : null;

    if (!destination || destination.mode === 'screen') {
        return [];
    }

    return destination.printerIds.filter((id) => state.printers[id]?.active !== false);
}

export function ticketPrinterId(): number | null {
    const printer = Object.values(state.printers).find((p) => p.active && p.isTicketPrinter);

    return printer?.id ?? null;
}

export function widthFor(printerId: number | null): number {
    return (printerId && state.printers[printerId]?.paperWidth) || DEFAULT_WIDTH;
}

export function job(kind: string, title: string, printerId: number | null, document: PrintDocument): PrintJobPayload {
    return { uuid: uuid(), kind, title, printerId, document };
}

function header(lines: PrintLine[], title: string, order: Order, width: number, extra: string[] = []): void {
    lines.push({ type: 'text', text: title, align: 'center', bold: true, size: 2, invert: true });
    lines.push({ type: 'text', text: `${t('reservations.table')} ${tableLabel(order)}`, align: 'center', bold: true, size: 2 });
    lines.push({ type: 'row', left: time(new Date().toISOString()), right: staffName(state.operatorId) });

    for (const text of extra) {
        lines.push({ type: 'text', text, align: 'center', bold: true });
    }

    lines.push({ type: 'divider' });
    void width;
}

function lineDescription(line: OrderLine, children: OrderLine[]): PrintLine[] {
    const out: PrintLine[] = [{ type: 'text', text: `${line.quantity} x ${tr(line.name)}`, bold: true, size: 2 }];

    for (const m of line.modifiers) {
        out.push({ type: 'text', text: `   + ${tr(m.name)}` });
    }

    for (const child of children) {
        out.push({ type: 'text', text: `   > ${child.quantity > 1 ? `${child.quantity} x ` : ''}${tr(child.name)}`, bold: true });

        for (const m of child.modifiers) {
            out.push({ type: 'text', text: `      + ${tr(m.name)}` });
        }

        if (child.note) {
            out.push({ type: 'text', text: `      ! ${child.note}`, bold: true });
        }
    }

    if (line.note) {
        out.push({ type: 'text', text: `   ! ${line.note}`, bold: true });
    }

    return out;
}

/**
 * Production ticket for one destination. Lines are grouped by course so the kitchen sees
 * what goes first.
 */
export function kitchenDocument(order: Order, destinationName: string, lines: OrderLine[], allLines: OrderLine[], opts: { held?: boolean; width?: number } = {}): PrintDocument {
    const width = opts.width ?? DEFAULT_WIDTH;
    const out: PrintLine[] = [];
    header(out, destinationName.toUpperCase(), order, width, order.guests ? [t('floor.guests', { count: order.guests })] : []);

    const byCourse = new Map<number, OrderLine[]>();

    for (const line of lines) {
        const course = line.course ?? 0;
        byCourse.set(course, [...(byCourse.get(course) ?? []), line]);
    }

    for (const course of [...byCourse.keys()].sort()) {
        if (course) {
            const heldText = opts.held && course >= 2 ? ` - ${t('order.held').toUpperCase()}` : '';
            out.push({ type: 'text', text: `-- ${t(`order.courses.${course}`).toUpperCase()}${heldText} --`, align: 'center', bold: true });
        }

        for (const line of byCourse.get(course) ?? []) {
            out.push(...lineDescription(line, allLines.filter((l) => l.parentUuid === line.uuid && !line.setMenuId)));
        }
    }

    out.push({ type: 'feed', lines: 2 }, { type: 'cut' });

    return { width, lines: out };
}

export function marchDocument(order: Order, destinationName: string, course: number, width = DEFAULT_WIDTH): PrintDocument {
    const out: PrintLine[] = [];
    header(out, destinationName.toUpperCase(), order, width);
    out.push({ type: 'text', text: t('order.march', { course: t(`order.courses.${course}`) }).toUpperCase(), align: 'center', bold: true, size: 2 });
    out.push({ type: 'feed', lines: 2 }, { type: 'cut' });

    return { width, lines: out };
}

export function voidDocument(order: Order, destinationName: string, line: OrderLine, quantity: number, reason: string | null, width = DEFAULT_WIDTH): PrintDocument {
    const out: PrintLine[] = [];
    header(out, `${t('order.voided').toUpperCase()} - ${destinationName.toUpperCase()}`, order, width);
    out.push({ type: 'text', text: `${quantity} x ${tr(line.name)}`, bold: true, size: 2 });

    if (reason) {
        out.push({ type: 'text', text: `${t('common.reason')}: ${reason}` });
    }

    out.push({ type: 'feed', lines: 2 }, { type: 'cut' });

    return { width, lines: out };
}

function businessHeader(out: PrintLine[]): void {
    const s = state.settings;

    if (!s) {
        return;
    }

    if (s.logo_url) {
        out.push({ type: 'image', url: s.logo_url });
    }

    out.push({ type: 'text', text: s.business_name, align: 'center', bold: true, size: 2 });
    out.push({ type: 'text', text: `${s.issuer_name} - NIF ${s.issuer_tax_id}`, align: 'center' });
    out.push({ type: 'text', text: s.issuer_address, align: 'center' });
    out.push({ type: 'text', text: `${s.issuer_postal_code} ${s.issuer_city} (${s.issuer_province})`, align: 'center' });

    if (s.issuer_phone) {
        out.push({ type: 'text', text: `Tel. ${s.issuer_phone}`, align: 'center' });
    }
}

export type TicketLineView = { name: string; quantity: number; unitPrice: number; total: number; vatRate: number; details?: string[]; discount?: number };

export type TicketView = {
    fullNumber: string | null;
    issuedAt: string;
    tableLabel: string | null;
    waiter: string;
    lines: TicketLineView[];
    surchargeRate: number;
    surchargeAmount: number;
    total: number;
    vatBreakdown: VatRow[];
    payments: { method: 'cash' | 'card'; amount: number; tendered?: number | null; change?: number | null }[];
    invoiceUrl?: string | null;
    verifactuUrl?: string | null;
    partLabel?: string | null;
    isBill?: boolean;
    tip?: number | null;
};

function ticketBody(out: PrintLine[], ticket: TicketView, width: number): void {
    out.push({ type: 'row', left: `${t('reservations.table')}: ${ticket.tableLabel ?? '-'}`, right: ticket.waiter });

    if (ticket.partLabel) {
        out.push({ type: 'text', text: ticket.partLabel, align: 'center', bold: true });
    }

    out.push({ type: 'divider' });

    for (const line of ticket.lines) {
        const qty = Number.isInteger(line.quantity) ? String(line.quantity) : line.quantity.toFixed(2);
        out.push({ type: 'row', left: `${qty} ${line.name}`.slice(0, width - 10), right: formatMoney(line.total, false) });

        if (line.quantity !== 1) {
            out.push({ type: 'text', text: `   ${qty} x ${formatMoney(line.unitPrice, false)}` });
        }

        for (const detail of line.details ?? []) {
            out.push({ type: 'text', text: `   ${detail}` });
        }

        if (line.discount) {
            out.push({ type: 'row', left: `   ${t('order.discount')}`, right: `-${formatMoney(line.discount, false)}` });
        }
    }

    out.push({ type: 'divider' });

    if (ticket.surchargeAmount || (ticket.surchargeRate > 0 && state.settings?.show_zero_surcharge)) {
        out.push({ type: 'row', left: t('cashier.surcharge', { percent: ticket.surchargeRate }), right: formatMoney(ticket.surchargeAmount, false) });
    }

    out.push({ type: 'row', left: t('common.total').toUpperCase(), right: formatMoney(ticket.total), bold: true, size: 2 });
    out.push({ type: 'divider', char: '-' });
    out.push({ type: 'row', left: `${t('common.vat')}  ${t('cashier.base')}`, right: t('cashier.quota') });

    for (const row of ticket.vatBreakdown) {
        out.push({ type: 'row', left: `${formatRate(row.rate).padEnd(5)} ${formatMoney(row.base, false)}`, right: formatMoney(row.vat, false) });
    }
}

export function billDocument(ticket: TicketView, width = DEFAULT_WIDTH): PrintDocument {
    const out: PrintLine[] = [];
    businessHeader(out);
    out.push({ type: 'divider' });
    out.push({ type: 'text', text: t('order.proforma').toUpperCase(), align: 'center', bold: true });
    out.push({ type: 'text', text: time(ticket.issuedAt), align: 'center' });
    ticketBody(out, ticket, width);
    out.push({ type: 'feed' });
    out.push({ type: 'text', text: '*** NO VÀLID COM A FACTURA / NO VÁLIDO COMO FACTURA ***', align: 'center' });
    out.push({ type: 'feed', lines: 2 }, { type: 'cut' });

    return { width, lines: out };
}

export function ticketDocument(ticket: TicketView, width = DEFAULT_WIDTH): PrintDocument {
    const out: PrintLine[] = [];
    businessHeader(out);
    out.push({ type: 'divider' });
    out.push({ type: 'text', text: 'FACTURA SIMPLIFICADA', align: 'center', bold: true });
    out.push({ type: 'row', left: ticket.fullNumber ?? '', right: time(ticket.issuedAt), bold: true });
    ticketBody(out, ticket, width);
    out.push({ type: 'divider', char: '-' });

    for (const payment of ticket.payments) {
        out.push({ type: 'row', left: t(`cashier.${payment.method}`), right: formatMoney(payment.amount, false) });

        if (payment.method === 'cash' && payment.tendered) {
            out.push({ type: 'row', left: `  ${t('cashier.tendered')}`, right: formatMoney(payment.tendered, false) });
            out.push({ type: 'row', left: `  ${t('cashier.change')}`, right: formatMoney(payment.change ?? 0, false) });
        }
    }

    if (ticket.tip && ticket.tip > 0) {
        out.push({ type: 'text', text: t('cashier.extraTip', { amount: formatMoney(ticket.tip) }), align: 'center', bold: true });
    }

    out.push({ type: 'feed' });

    if (ticket.verifactuUrl) {
        out.push({ type: 'qr', data: ticket.verifactuUrl, caption: 'VERI*FACTU (simulat / simulado)' });
    }

    if (ticket.invoiceUrl) {
        out.push({ type: 'qr', data: ticket.invoiceUrl, caption: t('cashier.invoiceQr') });
    }

    const footer = tr(state.settings?.ticket_footer);

    if (footer) {
        out.push({ type: 'text', text: footer, align: 'center' });
    }

    out.push({ type: 'feed', lines: 2 }, { type: 'cut' });

    return { width, lines: out };
}

export type ZSummary = {
    tickets: number;
    total: number;
    by_method: Record<string, number>;
    discounts: number;
    surcharge: number;
    voids: { count: number; amount: number };
    vat_breakdown: VatRow[];
    opening_float: number;
    expected_cash: number;
    first_ticket: string | null;
    last_ticket: string | null;
};

export function zDocument(summary: ZSummary, info: { zNumber: number | null; openedAt: string; closedAt: string; counted: number | null; difference: number | null; device: string; partial?: boolean }, width = DEFAULT_WIDTH): PrintDocument {
    const out: PrintLine[] = [];
    businessHeader(out);
    out.push({ type: 'divider' });
    out.push({ type: 'text', text: info.partial ? t('cashier.xReport').toUpperCase() : `${t('cashier.zReport').toUpperCase()} ${info.zNumber ? `#${info.zNumber}` : ''}`, align: 'center', bold: true, size: 2 });
    out.push({ type: 'text', text: info.device, align: 'center' });
    out.push({ type: 'row', left: t('admin.cashSessions.openedAt'), right: time(info.openedAt) });
    out.push({ type: 'row', left: t('admin.cashSessions.closedAt'), right: time(info.closedAt) });
    out.push({ type: 'divider' });
    out.push({ type: 'row', left: t('cashier.ticketCount'), right: String(summary.tickets) });

    if (summary.first_ticket) {
        out.push({ type: 'text', text: t('cashier.firstLast', { first: summary.first_ticket, last: summary.last_ticket ?? '' }) });
    }

    out.push({ type: 'row', left: t('common.total').toUpperCase(), right: formatMoney(summary.total), bold: true, size: 2 });
    out.push({ type: 'divider', char: '-' });
    out.push({ type: 'text', text: t('cashier.byMethod'), bold: true });

    for (const [method, amount] of Object.entries(summary.by_method)) {
        out.push({ type: 'row', left: t(`cashier.${method}`), right: formatMoney(amount, false) });
    }

    out.push({ type: 'divider', char: '-' });
    out.push({ type: 'text', text: t('cashier.vatBreakdown'), bold: true });

    for (const row of summary.vat_breakdown) {
        out.push({ type: 'row', left: `${formatRate(row.rate).padEnd(5)} ${formatMoney(row.base, false)}`, right: formatMoney(row.vat, false) });
    }

    out.push({ type: 'divider', char: '-' });
    out.push({ type: 'row', left: t('cashier.discounts'), right: formatMoney(summary.discounts, false) });
    out.push({ type: 'row', left: t('cashier.surcharge', { percent: state.settings?.terrace_surcharge_percent ?? 0 }), right: formatMoney(summary.surcharge, false) });
    out.push({ type: 'row', left: `${t('cashier.voids')} (${summary.voids.count})`, right: formatMoney(summary.voids.amount, false) });
    out.push({ type: 'divider', char: '-' });
    out.push({ type: 'row', left: t('cashier.openingFloat'), right: formatMoney(summary.opening_float, false) });
    out.push({ type: 'row', left: t('cashier.expected'), right: formatMoney(summary.expected_cash, false) });

    if (info.counted !== null) {
        out.push({ type: 'row', left: t('cashier.counted'), right: formatMoney(info.counted, false) });
        out.push({ type: 'row', left: t('cashier.difference'), right: formatMoney(info.difference ?? 0, false), bold: true });
    }

    out.push({ type: 'feed', lines: 2 }, { type: 'cut' });

    return { width, lines: out };
}

export function testDocument(printerName: string, width = DEFAULT_WIDTH): PrintDocument {
    return {
        width,
        lines: [
            { type: 'text', text: t('admin.printing.testPrint').toUpperCase(), align: 'center', bold: true, size: 2 },
            { type: 'text', text: printerName, align: 'center' },
            { type: 'text', text: time(new Date().toISOString()), align: 'center' },
            { type: 'divider' },
            { type: 'text', text: 'ÀÉÈÍÏÓÒÚÜÇ àéèíïóòúüç ñÑ €' },
            { type: 'row', left: 'Esquerra / Izquierda', right: 'Dreta / Derecha' },
            { type: 'qr', data: 'https://centrecivicpau.prismaengine.com' },
            { type: 'feed', lines: 2 },
            { type: 'cut' },
        ],
    };
}

export function lineAmount(line: OrderLine, allLines: OrderLine[], quantity = line.quantity): number {
    const childTotal = allLines.filter((l) => l.parentUuid === line.uuid).reduce((sum, child) => sum + netTotal(child), 0);
    const perUnitChildren = line.quantity > 0 ? childTotal / line.quantity : 0;

    return netTotal(line, quantity) + Math.round(perUnitChildren * quantity);
}
