import { activeOrderForTable, findLine, followOrder, markDirty, setKv, state, touchKv, upsert } from './store';
import type { ClockEvent, KitchenTicket, Operation, Order, OrderLine, Reservation, T9n, TicketSummary } from './types';

type Payload = Record<string, any>;

/**
 * Optimistic local versions of the server handlers (app/Services/Sync/Handlers). They must
 * be idempotent: pending operations are re-applied on top of every server pull.
 */
export function applyOperation(op: Pick<Operation, 'type' | 'payload' | 'createdAt' | 'operatorId' | 'uuid'>): void {
    const p = op.payload as Payload;

    switch (op.type) {
        case 'order.open':
            openOrder(p, op);
            break;
        case 'order.send':
            send(p, op);
            break;
        case 'order.march':
            march(p);
            break;
        case 'order.update':
            withOrder(p.orderUuid, (o) => {
                if (typeof p.guests === 'number') {
o.guests = p.guests;
}

                if (typeof p.label === 'string') {
o.label = p.label;
}
            });
            break;
        case 'order.move':
            move(p);
            break;
        case 'order.merge':
            mergeOrders(p.orderUuid, p.intoOrderUuid);
            break;
        case 'order.requestBill':
            withOrder(p.orderUuid, (o) => {
                o.status = 'bill_requested';
                o.billRequestedAt = op.createdAt;
            });
            break;
        case 'order.reopen':
            withOrder(p.orderUuid, (o) => {
                o.status = 'open';
                o.billRequestedAt = null;
            });
            break;
        case 'order.cancel':
            withOrder(p.orderUuid, (o) => {
                o.status = 'cancelled';
                o.closedAt = op.createdAt;
            });
            break;
        case 'order.discount':
            withOrder(p.orderUuid, (o) => {
                for (const line of o.lines) {
                    if (!line.voided && !line.parentUuid) {
                        setDiscount(line, 'percent', Number(p.percent ?? 0), p.reason ?? null);
                    }
                }
            });
            break;
        case 'line.void':
            voidLine(p);
            break;
        case 'line.discount': {
            const found = findLine(p.lineUuid);

            if (found) {
                setDiscount(found.line, p.type, Number(p.value ?? 0), p.reason ?? null);
                markDirty('orders', found.order.uuid);
            }

            break;
        }
        case 'product.soldOut': {
            const product = state.products[p.productId];

            if (product) {
                product.soldOut = !!p.soldOut;
                markDirty('products', product.id);
            }

            break;
        }
        case 'kds.status':
            kdsStatus(p, op.createdAt);
            break;
        case 'reservation.save':
            saveReservation(p);
            break;
        case 'reservation.status': {
            const reservation = state.reservations[p.uuid];

            if (reservation) {
                reservation.status = p.status;
                markDirty('reservations', reservation.uuid);
            }

            break;
        }
        case 'time.clock':
            clock(p, op);
            break;
        case 'cash.open': {
            const cashier = state.cashier;

            if (cashier && !cashier.session) {
                cashier.session = { uuid: p.sessionUuid, openedAt: p.openedAt ?? op.createdAt, openingFloat: p.openingFloat ?? 0, openedBy: op.operatorId };
                cashier.tickets = [];
                touchKv('cashier');
            }

            break;
        }
        case 'cash.close': {
            const cashier = state.cashier;

            if (cashier && cashier.session?.uuid === p.sessionUuid) {
                cashier.session = null;
                cashier.tickets = [];
                touchKv('cashier');
            }

            break;
        }
        case 'ticket.issue':
            issueTicket(p);
            break;
    }
}

function withOrder(uuid: string, fn: (order: Order) => void): void {
    const order = followOrder(uuid);

    if (order) {
        fn(order);
        markDirty('orders', order.uuid);
    }
}

function openOrder(p: Payload, op: Pick<Operation, 'createdAt' | 'operatorId'>): Order {
    const existing = followOrder(p.orderUuid);

    if (existing) {
        return existing;
    }

    const tableId = typeof p.tableId === 'number' ? p.tableId : null;
    const active = tableId !== null ? activeOrderForTable(tableId) : null;

    const order: Order = {
        uuid: p.orderUuid,
        tableId,
        status: active ? 'merged' : 'open',
        guests: typeof p.guests === 'number' ? p.guests : null,
        label: typeof p.label === 'string' ? p.label : null,
        openedBy: op.operatorId,
        openedAt: p.openedAt ?? op.createdAt,
        billRequestedAt: null,
        closedAt: null,
        mergedIntoUuid: active?.uuid ?? null,
        reservationUuid: p.reservationUuid ?? null,
        lines: [],
    };

    upsert('orders', order);

    if (p.reservationUuid && state.reservations[p.reservationUuid]) {
        const reservation = state.reservations[p.reservationUuid];
        reservation.status = 'seated';
        reservation.orderUuid = (active ?? order).uuid;
        reservation.tableId = tableId;
        markDirty('reservations', reservation.uuid);
    }

    return active ?? order;
}

type LineInput = Partial<OrderLine> & { uuid: string; name: T9n; children?: LineInput[] };

function addLine(order: Order, input: LineInput, parentUuid: string | null, createdBy: number, sentAt: string): void {
    if (!findLine(input.uuid)) {
        order.lines.push({
            uuid: input.uuid,
            parentUuid,
            productId: input.productId ?? null,
            setMenuId: input.setMenuId ?? null,
            destinationId: input.destinationId ?? null,
            name: input.name,
            quantity: Math.max(1, input.quantity ?? 1),
            unitPrice: input.unitPrice ?? 0,
            vatRate: input.vatRate ?? 10,
            modifiers: input.modifiers ?? [],
            note: input.note || null,
            course: input.course ?? null,
            discountType: null,
            discountValue: 0,
            discountReason: null,
            voided: false,
            voidReason: null,
            paidQuantity: 0,
            createdBy,
            sentAt,
        });

        if (input.productId && !parentUuid && state.products[input.productId]) {
            state.products[input.productId].orderCount += input.quantity ?? 1;
        }
    }

    for (const child of input.children ?? []) {
        addLine(order, child, input.uuid, createdBy, sentAt);
    }
}

function send(p: Payload, op: Pick<Operation, 'createdAt' | 'operatorId'>): void {
    const order = openOrder(p, op);
    const sentAt = p.sentAt ?? op.createdAt;

    if (order.status !== 'open' && order.status !== 'bill_requested') {
        order.status = 'open';
        order.closedAt = null;
    }

    for (const line of (p.lines ?? []) as LineInput[]) {
        addLine(order, line, null, op.operatorId, sentAt);
    }

    for (const ticket of (p.kitchenTickets ?? []) as Payload[]) {
        if (state.kitchenTickets[ticket.uuid]) {
            continue;
        }

        const lines = order.lines.filter((l) => (ticket.lineUuids ?? []).includes(l.uuid));
        const table = order.tableId ? state.tables[order.tableId] : null;
        const kt: KitchenTicket = {
            uuid: ticket.uuid,
            orderUuid: order.uuid,
            destinationId: ticket.destinationId,
            tableLabel: ticket.tableLabel ?? table?.label ?? order.label,
            course: ticket.course ?? null,
            held: !!ticket.held,
            status: 'pending',
            createdBy: op.operatorId,
            sentAt,
            startedAt: null,
            readyAt: null,
            servedAt: null,
            items: lines.map((l) => ({ lineUuid: l.uuid, name: l.name, quantity: l.quantity, modifiers: l.modifiers, note: l.note, voided: false })),
        };
        upsert('kitchenTickets', kt);
    }

    if (order.status === 'bill_requested') {
        order.status = 'open';
        order.billRequestedAt = null;
    }

    markDirty('orders', order.uuid);
}

function march(p: Payload): void {
    const order = followOrder(p.orderUuid);

    if (!order) {
        return;
    }

    for (const ticket of Object.values(state.kitchenTickets)) {
        if (ticket.orderUuid === order.uuid && ticket.course === (p.course ?? 2) && ticket.held) {
            ticket.held = false;
            markDirty('kitchenTickets', ticket.uuid);
        }
    }
}

function move(p: Payload): void {
    const order = followOrder(p.orderUuid);
    const tableId = Number(p.toTableId);

    if (!order || !state.tables[tableId]) {
        return;
    }

    const target = activeOrderForTable(tableId);

    if (target && target.uuid !== order.uuid) {
        mergeOrders(order.uuid, target.uuid);

        return;
    }

    order.tableId = tableId;

    for (const ticket of Object.values(state.kitchenTickets)) {
        if (ticket.orderUuid === order.uuid) {
            ticket.tableLabel = state.tables[tableId].label;
            markDirty('kitchenTickets', ticket.uuid);
        }
    }

    markDirty('orders', order.uuid);
}

function mergeOrders(sourceUuid: string, targetUuid: string): void {
    const source = followOrder(sourceUuid);
    const target = followOrder(targetUuid);

    if (!source || !target || source.uuid === target.uuid) {
        return;
    }

    for (const line of source.lines) {
        if (!target.lines.some((l) => l.uuid === line.uuid)) {
            target.lines.push(line);
        }
    }

    source.lines = [];
    source.status = 'merged';
    source.mergedIntoUuid = target.uuid;
    target.guests = (target.guests ?? 0) + (source.guests ?? 0) || null;

    if (source.openedAt < target.openedAt) {
        target.openedAt = source.openedAt;
    }

    const label = target.tableId ? state.tables[target.tableId]?.label : target.label;

    for (const ticket of Object.values(state.kitchenTickets)) {
        if (ticket.orderUuid === source.uuid) {
            ticket.orderUuid = target.uuid;
            ticket.tableLabel = label ?? ticket.tableLabel;
            markDirty('kitchenTickets', ticket.uuid);
        }
    }

    markDirty('orders', source.uuid);
    markDirty('orders', target.uuid);
}

function setDiscount(line: OrderLine, type: 'percent' | 'amount' | null, value: number, reason: string | null): void {
    if (!type || value <= 0) {
        line.discountType = null;
        line.discountValue = 0;
        line.discountReason = null;

        return;
    }

    line.discountType = type;
    line.discountValue = type === 'percent' ? Math.min(100, value) : Math.round(value);
    line.discountReason = reason;
}

function voidLine(p: Payload): void {
    const found = findLine(p.lineUuid);

    if (!found || found.line.voided) {
        return;
    }

    const { order, line } = found;
    const available = line.quantity - line.paidQuantity;
    const quantity = Math.min(available, Math.max(1, Number(p.quantity ?? available)));

    if (quantity <= 0) {
        return;
    }

    if (quantity >= line.quantity) {
        line.voided = true;
        line.voidReason = p.reason ?? null;

        for (const child of order.lines.filter((l) => l.parentUuid === line.uuid)) {
            child.voided = true;
        }
    } else if (p.splitUuid && !order.lines.some((l) => l.uuid === p.splitUuid)) {
        line.quantity -= quantity;
        order.lines.push({ ...line, uuid: p.splitUuid, quantity, paidQuantity: 0, voided: true, voidReason: p.reason ?? null, modifiers: [...line.modifiers] });
    }

    for (const ticket of Object.values(state.kitchenTickets)) {
        const item = ticket.items.find((i) => i.lineUuid === line.uuid);

        if (item) {
            if (line.voided) {
                item.voided = true;
            } else {
                item.quantity = line.quantity;
            }

            markDirty('kitchenTickets', ticket.uuid);
        }
    }

    markDirty('orders', order.uuid);
}

function kdsStatus(p: Payload, at: string): void {
    const ticket = state.kitchenTickets[p.ticketUuid];

    if (!ticket) {
        return;
    }

    ticket.status = p.status;

    if (p.status === 'pending') {
        ticket.startedAt = ticket.readyAt = ticket.servedAt = null;
    } else if (p.status === 'preparing') {
        ticket.startedAt ??= at;
        ticket.readyAt = ticket.servedAt = null;
    } else if (p.status === 'ready') {
        ticket.held = false;
        ticket.readyAt = at;
        ticket.servedAt = null;
    } else {
        ticket.servedAt = at;
    }

    markDirty('kitchenTickets', ticket.uuid);
}

function saveReservation(p: Payload): void {
    const existing = state.reservations[p.uuid];
    const reservation: Reservation = {
        uuid: p.uuid,
        name: p.name,
        phone: p.phone ?? null,
        partySize: Number(p.partySize ?? 2),
        reservedAt: p.reservedAt,
        durationMinutes: Number(p.durationMinutes ?? 90),
        zoneId: p.zoneId ?? (p.tableId ? (state.tables[p.tableId]?.zoneId ?? null) : null),
        tableId: p.tableId ?? null,
        notes: p.notes ?? null,
        status: existing?.status ?? 'confirmed',
        source: existing?.source ?? 'staff',
        orderUuid: existing?.orderUuid ?? null,
        deleted: false,
    };
    upsert('reservations', reservation);
}

function clock(p: Payload, op: Pick<Operation, 'operatorId'>): void {
    const current = state.myClock ?? { state: 'out', since: null, todayMinutes: 0, entries: [] };
    const at: string = p.occurredAt;

    if (op.operatorId !== state.me?.id || current.entries.some((e) => e.at === at && e.type === p.type)) {
        return;
    }

    const event: ClockEvent = { type: p.type, at, source: p.source, pending: true };
    const entries = [...current.entries, event].sort((a, b) => a.at.localeCompare(b.at));
    const status = p.type === 'clock_out' ? 'out' : p.type === 'break_start' ? 'break' : 'in';

    setKv('myClock', { ...current, state: status, since: at, entries });
}

function issueTicket(p: Payload): void {
    const ticket = p.ticket as Payload;

    if (!state.cashier || state.cashier.tickets.some((t) => t.uuid === ticket.uuid)) {
        return;
    }

    const summary: TicketSummary = {
        uuid: ticket.uuid,
        fullNumber: ticket.fullNumber,
        number: ticket.number,
        issuedAt: ticket.issuedAt,
        tableLabel: ticket.tableLabel ?? null,
        total: ticket.total,
        discountTotal: ticket.discountTotal ?? 0,
        surchargeAmount: ticket.surchargeAmount ?? 0,
        vatBreakdown: ticket.vatBreakdown ?? [],
        payments: (ticket.payments ?? []).map((x: Payload) => ({ method: x.method, amount: x.amount })),
        local: true,
        document: p.printJobs?.[0]?.document,
    };

    state.cashier.tickets.push(summary);

    if (state.cashier.series) {
        state.cashier.series.lastNumber = Math.max(state.cashier.series.lastNumber, ticket.number);
    }

    touchKv('cashier');

    const order = ticket.orderUuid ? state.orders[ticket.orderUuid] : null;

    if (!order) {
        return;
    }

    for (const paid of (p.paidLines ?? []) as Payload[]) {
        const line = order.lines.find((l) => l.uuid === paid.lineUuid);

        if (line) {
            line.paidQuantity = Math.min(line.quantity, line.paidQuantity + Math.max(0, Number(paid.quantity ?? line.quantity)));

            for (const child of order.lines.filter((l) => l.parentUuid === line.uuid)) {
                child.paidQuantity = child.quantity;
            }
        }
    }

    const pending = order.lines.some((l) => !l.voided && !l.parentUuid && l.paidQuantity < l.quantity);

    if (!pending || p.closeOrder) {
        order.status = 'paid';
        order.closedAt = ticket.issuedAt;
    }

    markDirty('orders', order.uuid);
}
