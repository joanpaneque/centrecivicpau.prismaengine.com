import { reactive, watch } from 'vue';
import { t, tr } from '@/i18n';
import { productDestination } from './catalog';
import { uuid } from './crypto';
import { computeTax, grossTotal, netTotal } from './money';
import { billDocument, job, kitchenDocument, lineAmount, marchDocument, printersFor, ticketPrinterId, voidDocument, widthFor } from './print';
import type { TicketView } from './print';
import { activeOrderForTable, followOrder, operatorId, state } from './store';
import { enqueue } from './sync';
import type { LineModifier, Order, OrderLine, PrintDocument, PrintJobPayload, Product, SetMenu, T9n } from './types';

export type DraftLine = {
    key: string;
    productId: number | null;
    setMenuId: number | null;
    name: T9n;
    unitPrice: number;
    /** No price yet: it can be set with the gear button and is required before charging. */
    unpriced: boolean;
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

export type InventedProduct = { name: T9n; unitPrice: number | null; destinationId: number | null };

export function draftFromCustom(name: T9n | string, unitPrice: number | null, destinationId: number | null, course: number | null = null): DraftLine {
    const label = typeof name === 'string' ? { ca: name.trim(), es: name.trim() } : { ca: name.ca.trim(), es: name.es.trim() };

    return {
        key: uuid(),
        productId: null,
        setMenuId: null,
        name: label,
        unitPrice: unitPrice ?? 0,
        unpriced: unitPrice === null,
        vatRate: Number(state.settings?.default_vat_rate ?? 10),
        modifiers: [],
        note: '',
        course,
        quantity: 1,
        destinationId,
        children: [],
    };
}

export function draftFromProduct(product: Product, modifiers: LineModifier[] = [], note = '', course: number | null = null, quantity = 1): DraftLine {
    return {
        key: uuid(),
        productId: product.id,
        setMenuId: null,
        name: product.name,
        unitPrice: product.price ?? 0,
        unpriced: product.price === null,
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
        unpriced: false,
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
            unpriced: false,
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
    const same = draft.lines.find((l) => {
        if (l.children.length || line.children.length || l.note !== line.note || l.course !== line.course) {
            return false;
        }

        if (l.unitPrice !== line.unitPrice || l.unpriced !== line.unpriced || l.name.ca !== line.name.ca || l.name.es !== line.name.es) {
            return false;
        }

        if (line.productId !== null) {
            return l.productId === line.productId && JSON.stringify(l.modifiers) === JSON.stringify(line.modifiers);
        }

        return l.productId === null && l.destinationId === line.destinationId;
    });

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
        unpriced: line.unpriced,
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
        unpriced: !!input.unpriced,
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
 * The order tickets of a send: the lines split by destination (bar, kitchen…), one job per
 * printer of each destination. Used when sending and by the simulation in Gestió → Tiquets.
 */
export function orderPrintJobs(order: Order, lines: OrderLine[], allLines: OrderLine[], held = false): PrintJobPayload[] {
    const byDestination = new Map<number, OrderLine[]>();

    for (const line of lines) {
        if (line.destinationId) {
            byDestination.set(line.destinationId, [...(byDestination.get(line.destinationId) ?? []), line]);
        }
    }

    const jobs: PrintJobPayload[] = [];

    for (const [destinationId, destinationLines] of byDestination) {
        const destination = state.destinations[destinationId];

        for (const printerId of printersFor(destinationId)) {
            const doc = kitchenDocument(order, tr(destination?.name), destinationLines, allLines, { held, width: widthFor(printerId) });
            jobs.push(job('order', `${tr(destination?.name)} · ${order.tableId ? state.tables[order.tableId]?.label : (order.label ?? '')}`, printerId, doc));
        }
    }

    return jobs;
}

/**
 * Send the draft: one operation carrying the lines, the kitchen tickets for screen
 * destinations and the print jobs for printer destinations. `silent` ("charge without
 * sending") only adds the lines to the order: nothing reaches the kitchen or the bar.
 */
export async function sendDraft(draft: Draft, holdSecond = false, silent = false): Promise<string> {
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
        if (!input.destinationId || silent) {
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

    printJobs.push(...orderPrintJobs(order, [...byDestination.values()].flat(), allLines, holdSecond));

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

/**
 * Identical lines sent in different rounds (two beers now, one more later) are printed as
 * one line on the customer's documents. Kitchen tickets keep each round separate.
 */
export function mergeViewLines(lines: TicketView['lines']): TicketView['lines'] {
    const merged = new Map<string, TicketView['lines'][number]>();

    for (const line of lines) {
        const key = JSON.stringify([line.name, line.unitPrice, line.vatRate, line.details ?? []]);
        const existing = merged.get(key);

        if (existing) {
            existing.quantity = Math.round((existing.quantity + line.quantity) * 1000) / 1000;
            existing.total += line.total;
            existing.discount = (existing.discount ?? 0) + (line.discount ?? 0);
        } else {
            merged.set(key, { ...line, details: [...(line.details ?? [])] });
        }
    }

    return [...merged.values()];
}

export function billViewFor(order: Order, lines?: { line: OrderLine; quantity: number }[]): TicketView {
    const view = ticketViewFor(order, lines);
    view.lines = mergeViewLines(view.lines);
    const tax = computeTax(view.lines.map((l) => ({ total: l.total, vatRate: l.vatRate })), view.surchargeRate);
    view.surchargeAmount = tax.surchargeAmount;
    view.total = tax.total;
    view.vatBreakdown = tax.vatBreakdown;

    return view;
}

export function proformaDocument(order: Order, lines?: { line: OrderLine; quantity: number }[]): PrintDocument {
    const view = billViewFor(order, lines);

    return billDocument(view, widthFor(ticketPrinterId()));
}

export function proformaTitle(order: Order): string {
    const label = order.tableId ? (state.tables[order.tableId]?.label ?? '') : (order.label ?? '');

    return `${t('order.proforma')}${label ? ` · ${label}` : ''}`;
}

export async function requestBill(order: Order, view: TicketView, opts: { print?: boolean } = {}): Promise<void> {
    const printerId = ticketPrinterId();
    const printJobs = opts.print === false ? [] : [job('bill', `${t('order.printBill')} · ${view.tableLabel ?? ''}`, printerId, billDocument(view, widthFor(printerId)))];
    await enqueue('order.requestBill', { orderUuid: order.uuid }, printJobs);
}

/**
 * Void `quantity` units of a group of identical lines, newest first. No reason is asked;
 * every destination that prepared the product gets one cancellation slip.
 */
export async function voidLines(order: Order, lines: OrderLine[], quantity: number, reason: string | null = null): Promise<void> {
    const parts: { line: OrderLine; quantity: number }[] = [];
    let remaining = quantity;

    for (const line of [...lines].reverse()) {
        const available = line.voided ? 0 : line.quantity - line.paidQuantity;
        const take = Math.min(available, remaining);

        if (take > 0) {
            parts.push({ line, quantity: take });
            remaining -= take;
        }
    }

    if (!parts.length) {
        return;
    }

    const printJobs: PrintJobPayload[] = [];
    const destinations = new Set<number>();
    const first = parts[0].line;
    const total = parts.reduce((sum, part) => sum + part.quantity, 0);

    for (const { line } of parts) {
        if (line.destinationId) {
            destinations.add(line.destinationId);
        }

        for (const child of childLines(order, line)) {
            if (child.destinationId) {
                destinations.add(child.destinationId);
            }
        }
    }

    for (const destinationId of destinations) {
        for (const printerId of printersFor(destinationId)) {
            printJobs.push(job('void', `${t('order.voided')} · ${tr(first.name)}`, printerId, voidDocument(order, tr(state.destinations[destinationId]?.name), first, total, reason, widthFor(printerId))));
        }
    }

    for (const [index, part] of parts.entries()) {
        await enqueue('line.void', { lineUuid: part.line.uuid, quantity: part.quantity, reason, splitUuid: uuid() }, index === 0 ? printJobs : []);
    }
}

export type LineEdit = { unitPrice: number; name: T9n | null; saveToCatalog: boolean };

/** Gear button on sent lines: new price and/or name, optionally saved to the catalog. */
export async function editSentLines(lines: OrderLine[], edit: LineEdit): Promise<void> {
    const first = lines[0];

    if (!first) {
        return;
    }

    await enqueue('line.edit', { lineUuids: lines.map((l) => l.uuid), unitPrice: edit.unitPrice, name: edit.name });

    if (edit.saveToCatalog && first.productId) {
        await saveProductPrice(first.productId, edit.unitPrice, edit.name);
    }
}

/** Gear button on unsent lines: they are still local, only the catalog needs an operation. */
export async function editDraftLine(line: DraftLine, edit: LineEdit): Promise<void> {
    line.unitPrice = edit.unitPrice;
    line.unpriced = false;

    if (edit.name) {
        line.name = edit.name;
    }

    if (edit.saveToCatalog && line.productId) {
        await saveProductPrice(line.productId, edit.unitPrice, edit.name);
    }
}

export async function saveProductPrice(productId: number, price: number, name: T9n | null = null): Promise<void> {
    await enqueue('product.edit', { productId, price, ...(name ? { name } : {}) });
}

export type LineGroup = {
    key: string;
    /** Lines in sending order; actions apply to all of them. */
    lines: OrderLine[];
    first: OrderLine;
    /** Units still to charge (all units for a voided group). */
    quantity: number;
    amount: number;
    voided: boolean;
};

/**
 * Lines as the waiter sees them: identical products sent in different rounds are shown as
 * one line with the summed quantity, and what has already been charged is hidden.
 */
export function groupOrderLines(order: Order | null): LineGroup[] {
    if (!order) {
        return [];
    }

    const groups = new Map<string, LineGroup>();

    for (const line of topLines(order)) {
        const pending = line.voided ? line.quantity : line.quantity - line.paidQuantity;

        if (pending <= 0) {
            continue;
        }

        const hasChildren = order.lines.some((l) => l.parentUuid === line.uuid);
        const key = hasChildren
            ? line.uuid
            : JSON.stringify([
                  line.voided,
                  line.productId,
                  line.setMenuId,
                  line.name.ca,
                  line.name.es,
                  line.unitPrice,
                  line.unpriced,
                  line.vatRate,
                  line.modifiers.map((m) => [m.id, m.priceDelta, typeof m.name === 'string' ? m.name : JSON.stringify(m.name)]),
                  line.note ?? '',
                  line.course,
                  line.discountType,
                  line.discountValue,
              ]);
        const amount = line.voided ? 0 : lineAmount(line, order.lines, pending);
        const group = groups.get(key);

        if (group) {
            group.lines.push(line);
            group.quantity += pending;
            group.amount += amount;
        } else {
            groups.set(key, { key, lines: [line], first: line, quantity: pending, amount, voided: line.voided });
        }
    }

    return [...groups.values()];
}

/** Lines that still need a price before the order can be charged. */
export function unpricedGroups(order: Order | null): LineGroup[] {
    return groupOrderLines(order).filter((group) => !group.voided && group.lines.some((l) => l.unpriced));
}

/** Unsent lines follow the order when it is moved to another table. */
export function moveDraft(fromTableId: number, toTableId: number): void {
    const from = drafts[`table:${fromTableId}`];

    if (!from) {
        return;
    }

    delete drafts[`table:${fromTableId}`];

    if (!from.lines.length) {
        return;
    }

    const target = drafts[`table:${toTableId}`];

    if (target) {
        target.lines.push(...from.lines);
    } else {
        from.tableId = toTableId;
        drafts[`table:${toTableId}`] = from;
    }
}

/**
 * Units of a (grouped) line that can go to another table: what is not paid yet. A line with
 * choices (a menu) only moves whole, so it counts only while none of it is paid.
 */
export function movableUnits(order: Order, lines: OrderLine[]): number {
    return lines.reduce((sum, line) => {
        if (line.voided) {
            return sum;
        }

        return sum + (childLines(order, line).length ? (line.paidQuantity === 0 ? line.quantity : 0) : line.quantity - line.paidQuantity);
    }, 0);
}

/**
 * Move `quantity` units of a (grouped) line to another table, which will pay for them. All
 * parts go to the same bill there (one is opened if the table has none).
 */
export async function moveLinesToTable(order: Order, lines: OrderLine[], quantity: number, toTableId: number): Promise<number> {
    const newOrderUuid = uuid();
    let remaining = quantity;

    for (const line of [...lines].reverse()) {
        if (remaining <= 0) {
            break;
        }

        if (line.voided) {
            continue;
        }

        const whole = childLines(order, line).length > 0;
        const available = whole ? (line.paidQuantity === 0 ? line.quantity : 0) : line.quantity - line.paidQuantity;
        const take = whole ? (remaining >= line.quantity ? available : 0) : Math.min(available, remaining);

        if (take <= 0) {
            continue;
        }

        await enqueue('line.move', { lineUuid: line.uuid, quantity: take, toTableId, newOrderUuid, newLineUuid: uuid() });
        remaining -= take;
    }

    return quantity - remaining;
}
