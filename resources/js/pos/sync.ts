import { reactive } from 'vue';
import { applyLocale } from '@/i18n';
import { echo } from '@/lib/echo';
import { api, HttpError, isNetworkError } from '@/lib/http';
import { uuid } from './crypto';
import type { ID_COLLECTIONS} from './db';
import { db, UUID_COLLECTIONS } from './db';
import { setPrintJobs } from './printQueue';
import { applyOperation } from './reducers';
import { flush, loadFromDisk, operatorId, remove, setKv, state, upsert } from './store';
import type { Operation, PrintJobPayload, Snapshot } from './types';

export const sync = reactive({
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    syncing: false,
    pending: 0,
    lastSyncAt: null as string | null,
    lastError: null as string | null,
    rejections: [] as { uuid: string; type: string; reason: string }[],
    needsLogin: false,
    started: false,
    /** Increments on each remote change notification so views can react (sounds, toasts). */
    remoteTick: 0,
});

const BATCH = 50;
let seq = Date.now();
let pushTimer: ReturnType<typeof setTimeout> | null = null;
let pullTimer: ReturnType<typeof setTimeout> | null = null;
let pollInterval: ReturnType<typeof setInterval> | null = null;
let backoff = 1000;
let pushing = false;
let pulling: Promise<void> | null = null;
let pullAgain = false;

async function refreshPending(): Promise<void> {
    sync.pending = await db.outbox.where('status').anyOf('pending', 'sending').count();
}

/**
 * Queue an operation: it is applied locally at once and sent when the network allows.
 */
export async function enqueue(type: string, payload: Record<string, unknown>, printJobs: PrintJobPayload[] = []): Promise<Operation> {
    const op: Operation = {
        uuid: uuid(),
        type,
        payload: printJobs.length ? { ...payload, printJobs } : payload,
        createdAt: new Date().toISOString(),
        operatorId: operatorId(),
        status: 'pending',
        attempts: 0,
        seq: seq++,
    };

    applyOperation(op);
    await db.outbox.put(JSON.parse(JSON.stringify(op)) as Operation);
    await flush();
    await refreshPending();
    schedulePush(0);

    return op;
}

function schedulePush(delay = 300): void {
    if (pushTimer) {
        clearTimeout(pushTimer);
    }

    pushTimer = setTimeout(() => void push(), delay);
}

export function schedulePull(delay = 250): void {
    if (pullTimer) {
        clearTimeout(pullTimer);
    }

    pullTimer = setTimeout(() => void pull(), delay);
}

async function push(): Promise<void> {
    if (pushing) {
        return;
    }

    const batch = await db.outbox.where('status').anyOf('pending', 'sending').sortBy('seq');

    if (!batch.length) {
        await refreshPending();

        return;
    }

    pushing = true;
    sync.syncing = true;

    try {
        const slice = batch.slice(0, BATCH);
        const response = await api<{ results: { uuid: string; status: string; reason?: string }[]; serverTime: string }>('POST', '/tpv/api/push', {
            operations: slice.map((op) => ({ uuid: op.uuid, type: op.type, payload: op.payload, createdAt: op.createdAt, operatorId: op.operatorId })),
        }, 30000);

        let stopped = false;

        for (const result of response.results) {
            const op = slice.find((o) => o.uuid === result.uuid);

            if (result.status === 'ok') {
                await db.outbox.delete(result.uuid);
            } else if (result.status === 'rejected') {
                await db.outbox.delete(result.uuid);
                sync.rejections.push({ uuid: result.uuid, type: op?.type ?? '', reason: result.reason ?? 'server_error' });
            } else {
                stopped = true;

                if (op) {
                    await db.outbox.update(op.uuid, { attempts: op.attempts + 1 });
                }
            }
        }

        setOnline(true);
        sync.lastError = stopped ? 'server_error' : null;
        backoff = stopped ? Math.min(backoff * 2, 60000) : 1000;
        await refreshPending();
        schedulePull(0);

        if (sync.pending > 0) {
            schedulePush(stopped ? backoff : 50);
        }
    } catch (error) {
        handleError(error);
        backoff = Math.min(backoff * 2, 60000);
        schedulePush(backoff);
    } finally {
        pushing = false;
        sync.syncing = false;
    }
}

function handleError(error: unknown): void {
    if (error instanceof HttpError && (error.status === 401 || error.status === 419)) {
        sync.needsLogin = true;
        setOnline(true);

        return;
    }

    if (isNetworkError(error)) {
        setOnline(false);
    }

    sync.lastError = error instanceof Error ? error.message : String(error);
}

function setOnline(value: boolean): void {
    if (sync.online !== value) {
        sync.online = value;

        if (value) {
            schedulePush(0);
        }
    }
}

export async function pull(full = false): Promise<void> {
    if (pulling) {
        pullAgain = true;

        return pulling;
    }

    pulling = (async () => {
        try {
            const since = full || !state.cursor ? null : state.cursor;
            const snapshot = await api<Snapshot>('GET', since ? `/tpv/api/pull?since=${encodeURIComponent(since)}` : '/tpv/api/bootstrap', undefined, 30000);
            await applySnapshot(snapshot);
            setOnline(true);
            sync.needsLogin = false;
            sync.lastSyncAt = new Date().toISOString();
        } catch (error) {
            handleError(error);
        } finally {
            pulling = null;

            if (pullAgain) {
                pullAgain = false;
                schedulePull(100);
            }
        }
    })();

    return pulling;
}

async function applySnapshot(snapshot: Snapshot): Promise<void> {
    const previousTickets = new Map((state.cashier?.tickets ?? []).map((t) => [t.uuid, t]));

    setKv('me', snapshot.me);
    setKv('device', snapshot.device);
    setKv('settings', snapshot.settings);
    setKv('myShifts', snapshot.myShifts);
    setKv('myClock', snapshot.myClock);
    setKv('clock', snapshot.clock);
    setKv('cursor', snapshot.cursor);

    if (snapshot.cashier) {
        snapshot.cashier.tickets = snapshot.cashier.tickets.map((t) => ({ ...t, document: previousTickets.get(t.uuid)?.document }));
    }

    setKv('cashier', snapshot.cashier);

    if (!state.operatorId || !snapshot.staff.some((s) => s.id === state.operatorId)) {
        setKv('operatorId', snapshot.me.id);
    }

    replaceAll('staff', snapshot.staff);
    replaceAll('destinations', snapshot.destinations);
    replaceAll('printers', snapshot.printers);

    if (snapshot.printJobs !== undefined && snapshot.printJobs !== null) {
        setPrintJobs(snapshot.printJobs);
    }

    for (const key of ['zones', 'tables', 'floorElements', 'categories', 'products', 'modifierGroups', 'setMenus'] as const) {
        const items = snapshot[key] ?? [];

        if (snapshot.full) {
            replaceAll(key, items as never[]);
        } else {
            for (const item of items) {
                upsert(key, item as never);
            }
        }
    }

    for (const key of ['orders', 'kitchenTickets', 'reservations'] as const) {
        if (snapshot.full) {
            replaceAll(key, snapshot[key] as never[]);
        } else {
            for (const item of snapshot[key]) {
                upsert(key, item as never);
            }
        }
    }

    pruneClosed();

    const pending = await db.outbox.where('status').anyOf('pending', 'sending').sortBy('seq');

    for (const op of pending) {
        applyOperation(op);
    }

    if (!state.loaded) {
        state.loaded = true;
        applyLocale(snapshot.me.locale);
    }

    await flush();
}

function replaceAll(collection: (typeof ID_COLLECTIONS)[number] | (typeof UUID_COLLECTIONS)[number], items: Record<string, unknown>[]): void {
    const isUuid = (UUID_COLLECTIONS as readonly string[]).includes(collection);
    const keep = new Set(items.map((i) => (isUuid ? i.uuid : i.id)));

    for (const key of Object.keys(state[collection])) {
        const typed = isUuid ? key : Number(key);

        if (!keep.has(typed)) {
            remove(collection, typed);
        }
    }

    for (const item of items) {
        upsert(collection, item as never);
    }
}

/** Closed orders and served kitchen tickets only matter for a while. */
function pruneClosed(): void {
    const limit = new Date(Date.now() - 6 * 3600 * 1000).toISOString();

    for (const order of Object.values(state.orders)) {
        if (order.status !== 'open' && order.status !== 'bill_requested' && (order.closedAt ?? order.openedAt) < limit) {
            remove('orders', order.uuid);
        }
    }

    const ticketLimit = new Date(Date.now() - 2 * 3600 * 1000).toISOString();

    for (const ticket of Object.values(state.kitchenTickets)) {
        if (ticket.status === 'served' && (ticket.servedAt ?? ticket.sentAt) < ticketLimit) {
            remove('kitchenTickets', ticket.uuid);
        }
    }
}

export async function startSync(): Promise<void> {
    if (sync.started) {
        return;
    }

    sync.started = true;
    await loadFromDisk();
    await refreshPending();

    if (state.loaded && state.me) {
        applyLocale(state.me.locale);
    }

    await pull(true);

    window.addEventListener('online', () => {
        setOnline(true);
        schedulePull(0);
    });
    window.addEventListener('offline', () => setOnline(false));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            schedulePull(0);
        }
    });

    pollInterval = setInterval(() => {
        schedulePull(0);

        if (sync.pending) {
            schedulePush(0);
        }
    }, 20000);

    const connection = echo();

    connection?.private('tpv').listen('.changed', (event: { scopes?: string[]; deviceUuid?: string | null }) => {
        if (event.deviceUuid && event.deviceUuid === state.device?.uuid) {
            return;
        }

        sync.remoteTick++;
        schedulePull(150);
    });

    if (sync.pending) {
        schedulePush(0);
    }
}

export function stopSync(): void {
    if (pollInterval) {
        clearInterval(pollInterval);
    }
}

export async function resetLocalData(): Promise<void> {
    const stores = (db as unknown as { tables: { name: string; clear: () => Promise<void> }[] }).tables.filter((table) => table.name !== 'outbox');

    for (const store of stores) {
        await store.clear();
    }

    window.location.reload();
}
