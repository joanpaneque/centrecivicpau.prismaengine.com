import Dexie from 'dexie';
import type { Table } from 'dexie';
import type {
    Category,
    Destination,
    DiningTable,
    FloorElement,
    KitchenTicket,
    ModifierGroup,
    Operation,
    Order,
    Printer,
    Product,
    Reservation,
    SetMenu,
    Staff,
    Zone,
} from './types';

export type KvRow = { key: string; value: unknown };

class PosDatabase extends Dexie {
    kv!: Table<KvRow, string>;
    zones!: Table<Zone, number>;
    diningTables!: Table<DiningTable, number>;
    floorElements!: Table<FloorElement, number>;
    destinations!: Table<Destination, number>;
    printers!: Table<Printer, number>;
    categories!: Table<Category, number>;
    products!: Table<Product, number>;
    modifierGroups!: Table<ModifierGroup, number>;
    setMenus!: Table<SetMenu, number>;
    staff!: Table<Staff, number>;
    orders!: Table<Order, string>;
    kitchenTickets!: Table<KitchenTicket, string>;
    reservations!: Table<Reservation, string>;
    outbox!: Table<Operation, string>;

    constructor() {
        super('tpv-centre-civic');

        this.version(1).stores({
            kv: 'key',
            zones: 'id',
            diningTables: 'id',
            destinations: 'id',
            printers: 'id',
            categories: 'id',
            products: 'id',
            modifierGroups: 'id',
            setMenus: 'id',
            staff: 'id',
            orders: 'uuid,status',
            kitchenTickets: 'uuid',
            reservations: 'uuid',
            outbox: 'uuid,seq,status',
        });

        this.version(2).stores({ floorElements: 'id' });
    }
}

export const db = new PosDatabase();

export const ID_COLLECTIONS = ['zones', 'tables', 'floorElements', 'destinations', 'printers', 'categories', 'products', 'modifierGroups', 'setMenus', 'staff'] as const;
export const UUID_COLLECTIONS = ['orders', 'kitchenTickets', 'reservations'] as const;
export const KV_KEYS = ['me', 'device', 'settings', 'cashier', 'myShifts', 'myClock', 'clock', 'cursor', 'operatorId', 'seriesNext'] as const;

export type IdCollection = (typeof ID_COLLECTIONS)[number];
export type UuidCollection = (typeof UUID_COLLECTIONS)[number];
export type Collection = IdCollection | UuidCollection;
export type KvKey = (typeof KV_KEYS)[number];

type AnyTable = { bulkPut: (rows: unknown[]) => Promise<unknown>; bulkDelete: (keys: unknown[]) => Promise<unknown>; toArray: () => Promise<Record<string, unknown>[]> };

/** Dexie reserves `tables`, so the dining tables store has another name. */
export function storeOf(collection: Collection): AnyTable {
    return (collection === 'tables' ? db.diningTables : db[collection]) as unknown as AnyTable;
}
