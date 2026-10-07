import { computed, reactive, toRaw } from 'vue';
import { db, ID_COLLECTIONS, KV_KEYS, storeOf, UUID_COLLECTIONS } from './db';
import type { Collection, KvKey } from './db';
import type {
    CashierState,
    Category,
    ClockState,
    Destination,
    DeviceInfo,
    DiningTable,
    FloorElement,
    KitchenTicket,
    Me,
    ModifierGroup,
    Order,
    OrderLine,
    Printer,
    Product,
    Reservation,
    SetMenu,
    Settings,
    Shift,
    Staff,
    Zone,
} from './types';

export type PosState = {
    loaded: boolean;
    me: Me | null;
    device: DeviceInfo | null;
    settings: Settings | null;
    cashier: CashierState | null;
    myShifts: Shift[];
    myClock: ClockState | null;
    clock: { secret: string; seconds: number; url: string } | null;
    cursor: string | null;
    operatorId: number | null;
    /** Next local ticket number of this cashier's series (survives offline restarts). */
    seriesNext: number | null;
    zones: Record<number, Zone>;
    tables: Record<number, DiningTable>;
    floorElements: Record<number, FloorElement>;
    destinations: Record<number, Destination>;
    printers: Record<number, Printer>;
    categories: Record<number, Category>;
    products: Record<number, Product>;
    modifierGroups: Record<number, ModifierGroup>;
    setMenus: Record<number, SetMenu>;
    staff: Record<number, Staff>;
    orders: Record<string, Order>;
    kitchenTickets: Record<string, KitchenTicket>;
    reservations: Record<string, Reservation>;
};

export const state = reactive<PosState>({
    loaded: false,
    me: null,
    device: null,
    settings: null,
    cashier: null,
    myShifts: [],
    myClock: null,
    clock: null,
    cursor: null,
    operatorId: null,
    seriesNext: null,
    zones: {},
    tables: {},
    floorElements: {},
    destinations: {},
    printers: {},
    categories: {},
    products: {},
    modifierGroups: {},
    setMenus: {},
    staff: {},
    orders: {},
    kitchenTickets: {},
    reservations: {},
});

const dirty = new Map<Collection, Set<string | number>>();
const removed = new Map<Collection, Set<string | number>>();
const dirtyKv = new Set<KvKey>();
let flushTimer: ReturnType<typeof setTimeout> | null = null;

function keyOf(collection: Collection, item: Record<string, unknown>): string | number {
    return (UUID_COLLECTIONS as readonly string[]).includes(collection) ? (item.uuid as string) : (item.id as number);
}

function addTo(map: Map<Collection, Set<string | number>>, collection: Collection, key: string | number): void {
    const set = map.get(collection);

    if (set) {
        set.add(key);
    } else {
        map.set(collection, new Set([key]));
    }
}

export function upsert<C extends Collection>(collection: C, item: PosState[C][keyof PosState[C]]): void {
    const record = item as unknown as Record<string, unknown>;
    const key = keyOf(collection, record);
    (state[collection] as Record<string | number, unknown>)[key] = item;
    markDirty(collection, key);
}

export function remove(collection: Collection, key: string | number): void {
    delete (state[collection] as Record<string | number, unknown>)[key];
    addTo(removed, collection, key);
    dirty.get(collection)?.delete(key);
    scheduleFlush();
}

export function markDirty(collection: Collection, key: string | number): void {
    addTo(dirty, collection, key);
    removed.get(collection)?.delete(key);
    scheduleFlush();
}

export function setKv<K extends KvKey>(key: K, value: PosState[K]): void {
    (state as Record<string, unknown>)[key] = value;
    dirtyKv.add(key);
    scheduleFlush();
}

export function touchKv(key: KvKey): void {
    dirtyKv.add(key);
    scheduleFlush();
}

function scheduleFlush(): void {
    if (flushTimer) {
        return;
    }

    flushTimer = setTimeout(() => {
        flushTimer = null;
        void flush();
    }, 50);
}

function plain<T>(value: T): T {
    return JSON.parse(JSON.stringify(toRaw(value))) as T;
}

export async function flush(): Promise<void> {
    const work: Promise<unknown>[] = [];

    for (const [collection, keys] of dirty) {
        const items = [...keys].map((key) => (state[collection] as Record<string | number, unknown>)[key]).filter(Boolean);

        if (items.length) {
            work.push(storeOf(collection).bulkPut(items.map(plain)));
        }
    }

    for (const [collection, keys] of removed) {
        if (keys.size) {
            work.push(storeOf(collection).bulkDelete([...keys]));
        }
    }

    if (dirtyKv.size) {
        work.push(db.kv.bulkPut([...dirtyKv].map((key) => ({ key, value: plain((state as Record<string, unknown>)[key]) }))));
    }

    dirty.clear();
    removed.clear();
    dirtyKv.clear();

    await Promise.all(work);
}

export async function loadFromDisk(): Promise<boolean> {
    const kv = await db.kv.toArray();

    for (const row of kv) {
        if ((KV_KEYS as readonly string[]).includes(row.key)) {
            (state as Record<string, unknown>)[row.key] = row.value;
        }
    }

    for (const collection of [...ID_COLLECTIONS, ...UUID_COLLECTIONS]) {
        const rows = (await storeOf(collection).toArray()) ?? [];
        const map: Record<string | number, unknown> = {};

        for (const row of rows) {
            map[keyOf(collection, row)] = row;
        }

        (state as Record<string, unknown>)[collection] = map;
    }

    state.loaded = state.me !== null;

    return state.loaded;
}

export function followOrder(uuid: string | null | undefined): Order | null {
    let order = uuid ? state.orders[uuid] : undefined;
    let guard = 0;

    while (order && order.status === 'merged' && order.mergedIntoUuid && guard++ < 10) {
        const next = state.orders[order.mergedIntoUuid];

        if (!next) {
            break;
        }

        order = next;
    }

    return order ?? null;
}

export function findLine(lineUuid: string): { order: Order; line: OrderLine } | null {
    for (const order of Object.values(state.orders)) {
        const line = order.lines.find((l) => l.uuid === lineUuid);

        if (line) {
            return { order, line };
        }
    }

    return null;
}

export function isActive(order: Order | null | undefined): boolean {
    return !!order && (order.status === 'open' || order.status === 'bill_requested');
}

export function activeOrderForTable(tableId: number): Order | null {
    return Object.values(state.orders).find((o) => o.tableId === tableId && isActive(o)) ?? null;
}

export const sorted = {
    zones: computed(() => Object.values(state.zones).filter((z) => !z.deleted).sort((a, b) => a.sort - b.sort || a.id - b.id)),
    tables: computed(() => Object.values(state.tables).filter((t) => !t.deleted).sort((a, b) => a.sort - b.sort || a.id - b.id)),
    floorElements: computed(() => Object.values(state.floorElements).filter((e) => !e.deleted).sort((a, b) => a.sort - b.sort || a.id - b.id)),
    categories: computed(() => Object.values(state.categories).filter((c) => !c.deleted && c.active).sort((a, b) => a.sort - b.sort || a.id - b.id)),
    products: computed(() => Object.values(state.products).filter((p) => !p.deleted && p.active).sort((a, b) => a.sort - b.sort || a.id - b.id)),
    activeOrders: computed(() => Object.values(state.orders).filter(isActive).sort((a, b) => a.openedAt.localeCompare(b.openedAt))),
    staff: computed(() => Object.values(state.staff).sort((a, b) => a.name.localeCompare(b.name))),
    destinations: computed(() => Object.values(state.destinations)),
};

export function operator(): Staff | Me | null {
    if (state.operatorId && state.staff[state.operatorId]) {
        return state.staff[state.operatorId];
    }

    return state.me;
}

export function operatorId(): number {
    return state.operatorId ?? state.me?.id ?? 0;
}
