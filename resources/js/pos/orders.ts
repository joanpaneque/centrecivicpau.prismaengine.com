import { reactive, watch } from 'vue';
import { t, tr } from '@/i18n';
import { productDestination } from './catalog';
import { uuid } from './crypto';
import { grossTotal, netTotal } from './money';
import { billDocument, job, kitchenDocument, lineAmount, marchDocument, printersFor, ticketPrinterId, voidDocument, widthFor } from './print';
import type { TicketView } from './print';
import { activeOrderForTable, followOrder, operatorId, state } from './store';
import { enqueue } from './sync';
import type { LineModifier, Order, OrderLine, PrintJobPayload, Product, SetMenu, T9n } from './types';

export type DraftLine = {
    key: string;
    productId: number | null;
    setMenuId: number | null;
    name: T9n;
    unitPrice: number;
    vatRate: number;
    modifiers: LineModifier[];
    note: string;
    course: number | null;
    quantity: number;
    destinationId: number | null;
    children: DraftLine[];
};

export type Draft = {
    orderUuid: string;
    tableId: number | null;
    label: string | null;
    guests: number | null;
    reservationUuid: string | null;
    lines: DraftLine[];
};

const STORAGE_KEY = 'tpv-drafts';

function loadDrafts(): Record<string, Draft> {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') as Record<string, Draft>;
    } catch {
        return {};
    }
}

/** Unsent lines survive reloads so nothing typed on a tablet is lost. */
export const drafts = reactive<Record<string, Draft>>(loadDrafts());

watch(drafts, (value) => localStorage.setItem(STORAGE_KEY, JSON.stringify(value)), { deep: true });

export function draftFor(order: Order | null, tableId: number | null, label: string | null = null): Draft {
    const key = tableId ? `table:${tableId}` : (order?.uuid ?? `new:${label ?? 'quick'}`);

    if (!drafts[key]) {
        drafts[key] = {
            orderUuid: order?.uuid ?? uuid(),
            tableId: order?.tableId ?? tableId,
            label: order?.label ?? label,
            guests: order?.guests ?? null,
            reservationUuid: null,
            lines: [],
        };
    }

    return drafts[key];
}

export function draftKeyOf(draft: Draft): string | undefined {
    return Object.keys(drafts).find((key) => drafts[key] === draft || drafts[key].orderUuid === draft.orderUuid);
}

export function discardDraft(draft: Draft): void {
    const key = draftKeyOf(draft);

    if (key) {
        delete drafts[key];
    }
}

export function draftLineTotal(line: DraftLine): number {
    const unit = line.unitPrice + line.modifiers.reduce((sum, m) => sum + m.priceDelta, 0);
    const children = line.children.reduce((sum, c) => sum + draftLineTotal(c), 0);

    return unit * line.quantity + children * line.quantity;
}

export function draftFromProduct(product: Product, modifiers: LineModifier[] = [], note = '', course: number | null = null, quantity = 1): DraftLine {
    return {
        key: uuid(),
        productId: product.id,
        setMenuId: null,
        name: product.name,
        unitPrice: product.price,
        vatRate: product.vatRate,
        modifiers,
        note,
        course,
        quantity,
        destinationId: productDestination(product),
        children: [],
    };
}

export function draftFromMenu(menu: SetMenu, choices: { name: T9n; productId: number | null; destinationId: number | null; supplement: number; course: number | null }[]): DraftLine {
    return {
        key: uuid(),
        productId: null,
        setMenuId: menu.id,
        name: menu.name,
        unitPrice: menu.price,
        vatRate: menu.vatRate,
        modifiers: [],
        note: '',
        course: null,
        quantity: 1,
        destinationId: null,
        children: choices.map((choice) => ({
            key: uuid(),
            productId: choice.productId,
            setMenuId: menu.id,
            name: choice.name,
            unitPrice: choice.supplement,
            vatRate: menu.vatRate,
            modifiers: [],
            note: '',
            course: choice.course,
            quantity: 1,
            destinationId: choice.destinationId,
            children: [],
        })),
    };
}

export function addToDraft(draft: Draft, line: DraftLine): void {
    const same = draft.lines.find(
        (l) =>
            l.productId !== null &&
            l.productId === line.productId &&
            !l.children.length &&
            !line.children.length &&
            l.note === line.note &&
            l.course === line.course &&
            JSON.stringify(l.modifiers) === JSON.stringify(line.modifiers),
    );

    if (same) {
        same.quantity += line.quantity;
    } else {
        draft.lines.push(line);
    }
}

export function topLines(order: Order | null): OrderLine[] {
    return order ? order.lines.filter((l) => !l.parentUuid) : [];
}

export function childLines(order: Order, line: OrderLine): OrderLine[] {
    return order.lines.filter((l) => l.parentUuid === line.uuid);
}

/** Pending amount (not paid, not voided) including set-menu supplements. */
export function orderTotal(order: Order | null): number {
    if (!order) {
        return 0;
    }

    return topLines(order).reduce((sum, line) => sum + (line.voided ? 0 : lineAmount(line, order.lines, line.quantity - line.paidQuantity)), 0);
}

export function orderGross(order: Order): number {
    return topLines(order).reduce((sum, line) => sum + (line.voided ? 0 : grossTotal(line)), 0);
}

export function tableStatus(tableId: number) {
    const order = activeOrderForTable(tableId);
    const now = Date.now();
    const lead = (state.settings?.reservation_lead_minutes ?? 60) * 60000;
    const reservation = Object.values(state.reservations).find((r) => {
        if (r.deleted || r.status !== 'confirmed' || r.tableId !== tableId) {
            return false;
        }

        const at = new Date(r.reservedAt).getTime();

        return at - lead <= now && at + 30 * 60000 >= now;
    });
    const ready = order ? Object.values(state.kitchenTickets).some((k) => k.orderUuid === order.uuid && k.status === 'ready') : false;

    return {
        order,
        status: order ? (order.status === 'bill_requested' ? 'bill' : 'occupied') : reservation ? 'reserved' : 'free',
        total: orderTotal(order),
        reservation,
        ready,
    } as const;
}

function toLineInput(line: DraftLine, lineUuid = uuid()): Record<string, unknown> & { uuid: string; children: Record<string, unknown>[] } {
    return {
        uuid: lineUuid,
        productId: line.productId,
        setMenuId: line.setMenuId,
        destinationId: line.destinationId,
        name: line.name,
        quantity: line.quantity,
        unitPrice: line.unitPrice,
        vatRate: line.vatRate,
        modifiers: line.modifiers,
        note: line.note || null,
        course: line.course,
        children: line.children.map((child) => toLineInput(child)),
    };
}

function flatten(inputs: ReturnType<typeof toLineInput>[]): ReturnType<typeof toLineInput>[] {
    return inputs.flatMap((input) => [input, ...flatten(input.children as ReturnType<typeof toLineInput>[])]);
}

function asOrderLine(input: Record<string, any>, parentUuid: string | null): OrderLine {
    return {
        uuid: input.uuid,
        parentUuid,
        productId: input.productId,
        setMenuId: input.setMenuId,
        destinationId: input.destinationId,
        name: input.name,
        quantity: input.quantity,
        unitPrice: input.unitPrice,
        vatRate: input.vatRate,
        modifiers: input.modifiers,
        note: input.note,
        course: input.course,
        discountType: null,
        discountValue: 0,
        discountReason: null,
        voided: false,
        voidReason: null,
        paidQuantity: 0,
        createdBy: operatorId(),
        sentAt: new Date().toISOString(),
    };
}

function previewOrder(draft: Draft): Order {
    const existing = followOrder(draft.orderUuid) ?? (draft.tableId ? activeOrderForTable(draft.tableId) : null);

    return (
        existing ?? {
            uuid: draft.orderUuid,
            tableId: draft.tableId,
            status: 'open',
            guests: draft.guests,
            label: draft.label,
            openedBy: operatorId(),
            openedAt: new Date().toISOString(),
            billRequestedAt: null,
            closedAt: null,
            mergedIntoUuid: null,
            reservationUuid: draft.reservationUuid,
            lines: [],
        }
    );
}

/**
 * Send the draft: one operation carrying the lines, the kitchen tickets for screen
 * destinations and the print jobs for printer destinations.
 */
export async function sendDraft(draft: Draft, holdSecond = false): Promise<string> {
    const current = draft.tableId ? activeOrderForTable(draft.tableId) : followOrder(draft.orderUuid);

    if (current && (current.status === 'open' || current.status === 'bill_requested')) {
        draft.orderUuid = current.uuid;
    } else if (state.orders[draft.orderUuid]) {
        draft.orderUuid = uuid();
    }

    const inputs = draft.lines.map((line) => toLineInput(line));
    const flat = flatten(inputs);
    const order = previewOrder(draft);
    const lineMap = new Map<string, OrderLine>();

    for (const input of inputs) {
        lineMap.set(input.uuid, asOrderLine(input, null));

        for (const child of input.children) {
            lineMap.set(child.uuid as string, asOrderLine(child, input.uuid));
        }
    }

    const groups = new Map<string, { destinationId: number; held: boolean; course: number | null; lines: OrderLine[] }>();

    for (const input of flat) {
        if (!input.destinationId) {
            continue;
        }

        const line = lineMap.get(input.uuid)!;
        const held = holdSecond && (line.course ?? 1) >= 2;
        const key = `${input.destinationId}:${held ? 'h' : 'n'}`;
        const group = groups.get(key) ?? { destinationId: input.destinationId as number, held, course: held ? 2 : null, lines: [] };
        group.lines.push(line);
        groups.set(key, group);
    }

    const kitchenTickets: Record<string, unknown>[] = [];
    const printJobs: PrintJobPayload[] = [];
    const allLines = [...lineMap.values()];

    const byDestination = new Map<number, OrderLine[]>();

    for (const group of groups.values()) {
        const destination = state.destinations[group.destinationId];

        if (destination && destination.mode !== 'printer') {
            kitchenTickets.push({
                uuid: uuid(),
                destinationId: group.destinationId,
                tableLabel: order.tableId ? state.tables[order.tableId]?.label : order.label,
                course: group.course,
                held: group.held,
                lineUuids: group.lines.map((l) => l.uuid),
            });
        }

        byDestination.set(group.destinationId, [...(byDestination.get(group.destinationId) ?? []), ...group.lines]);
    }

    for (const [destinationId, lines] of byDestination) {
        const destination = state.destinations[destinationId];

        for (const printerId of printersFor(destinationId)) {
            const doc = kitchenDocument(order, tr(destination?.name), lines, allLines, { held: holdSecond, width: widthFor(printerId) });
            printJobs.push(job('order', `${tr(destination?.name)} · ${order.tableId ? state.tables[order.tableId]?.label : (order.label ?? '')}`, printerId, doc));
        }
    }

    await enqueue(
        'order.send',
        {
            orderUuid: draft.orderUuid,
            tableId: draft.tableId,
            guests: draft.guests,
            label: draft.label,
            reservationUuid: draft.reservationUuid,
            openedAt: new Date().toISOString(),
            sentAt: new Date().toISOString(),
            lines: inputs,
            kitchenTickets,
        },
        printJobs,
    );

    const resolved = followOrder(draft.orderUuid);
    discardDraft(draft);

    return resolved?.uuid ?? draft.orderUuid;
}

export async function marchCourse(order: Order, course = 2): Promise<void> {
    const destinations = new Set(
        Object.values(state.kitchenTickets)
            .filter((k) => k.orderUuid === order.uuid && k.held && k.course === course)
            .map((k) => k.destinationId),
    );

    for (const line of order.lines) {
        if ((line.course ?? 0) >= course && line.destinationId && state.destinations[line.destinationId]?.mode === 'printer' && !line.voided) {
            destinations.add(line.destinationId);
        }
    }

    const printJobs: PrintJobPayload[] = [];

    for (const destinationId of destinations) {
        for (const printerId of printersFor(destinationId)) {
            printJobs.push(job('march', `${t('order.march', { course: t(`order.courses.${course}`) })}`, printerId, marchDocument(order, tr(state.destinations[destinationId]?.name), course, widthFor(printerId))));
        }
    }

    await enqueue('order.march', { orderUuid: order.uuid, course }, printJobs);
}

export function ticketViewFor(order: Order, lines?: { line: OrderLine; quantity: number }[]): TicketView {
    const zone = order.tableId ? state.zones[state.tables[order.tableId]?.zoneId] : null;
    const surchargeRate = zone?.appliesTerraceSurcharge ? Number(state.settings?.terrace_surcharge_percent ?? 0) : 0;
    const selected = lines ?? topLines(order).filter((l) => !l.voided && l.paidQuantity < l.quantity).map((line) => ({ line, quantity: line.quantity - line.paidQuantity }));

    const viewLines = selected.map(({ line, quantity }) => {
        const children = childLines(order, line).filter((c) => !c.voided);
        const total = lineAmount(line, order.lines, quantity);
        const gross = (line.unitPrice + line.modifiers.reduce((s, m) => s + m.priceDelta, 0)) * quantity + children.reduce((s, c) => s + netTotal(c), 0) * (line.quantity ? quantity / line.quantity : 1);

        return {
            name: tr(line.name),
            quantity,
            unitPrice: quantity ? Math.round(gross / quantity) : 0,
            total,
            vatRate: line.vatRate,
            details: [...line.modifiers.map((m) => `+ ${tr(m.name)}`), ...children.map((c) => `· ${tr(c.name)}`)],
            discount: Math.max(0, Math.round(gross) - total),
        };
    });

    return {
        fullNumber: null,
        issuedAt: new Date().toISOString(),
        tableLabel: order.tableId ? (state.tables[order.tableId]?.label ?? null) : order.label,
        waiter: (order.openedBy && state.staff[order.openedBy]?.name) || '',
        lines: viewLines,
        surchargeRate,
        surchargeAmount: 0,
        total: 0,
        vatBreakdown: [],
        payments: [],
    };
}

export async function requestBill(order: Order, view: TicketView): Promise<void> {
    const printerId = ticketPrinterId();
    const printJobs = [job('bill', `${t('order.printBill')} · ${view.tableLabel ?? ''}`, printerId, billDocument(view, widthFor(printerId)))];
    await enqueue('order.requestBill', { orderUuid: order.uuid }, printJobs);
}

export async function voidOrderLine(order: Order, line: OrderLine, quantity: number, reason: string): Promise<void> {
    const printJobs: PrintJobPayload[] = [];
    const destinations = new Set<number>();

    if (line.destinationId) {
        destinations.add(line.destinationId);
    }

    for (const child of childLines(order, line)) {
        if (child.destinationId) {
            destinations.add(child.destinationId);
        }
    }

    for (const destinationId of destinations) {
        for (const printerId of printersFor(destinationId)) {
            printJobs.push(job('void', `${t('order.voided')} · ${tr(line.name)}`, printerId, voidDocument(order, tr(state.destinations[destinationId]?.name), line, quantity, reason, widthFor(printerId))));
        }
    }

    await enqueue('line.void', { lineUuid: line.uuid, quantity, reason, splitUuid: uuid() }, printJobs);
}
