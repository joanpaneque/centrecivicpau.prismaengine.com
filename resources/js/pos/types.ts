import type { Translatable } from '@/i18n';

export type T9n = { ca: string; es: string };
export type Role = 'admin' | 'staff' | 'kitchen';
export type DeviceType = 'tablet' | 'cashier' | 'kds' | 'clock';

export type Me = { id: number; name: string; role: Role; locale: string; color: string | null; email: string | null };
export type DeviceInfo = { uuid: string; name: string; type: DeviceType };

export type Staff = { id: number; name: string; role: Role; color: string | null; locale: string; pinDigest: string | null };

export type Settings = {
    business_name: string;
    issuer_name: string;
    issuer_tax_id: string;
    issuer_address: string;
    issuer_postal_code: string;
    issuer_city: string;
    issuer_province: string;
    issuer_phone: string;
    issuer_email: string;
    logo_url: string | null;
    ticket_footer: T9n;
    terrace_surcharge_percent: number;
    show_zero_surcharge: boolean;
    default_vat_rate: number;
    default_locale: string;
    reservation_lead_minutes: number;
    clock_qr_seconds: number;
    clock_out_reminder_minutes: number;
    [key: string]: unknown;
};

export type Zone = { id: number; name: T9n; slug: string; appliesTerraceSurcharge: boolean; isBar: boolean; sort: number; deleted: boolean };

export type TableShape = 'square' | 'round' | 'rect' | 'stool';

export type DiningTable = {
    id: number;
    zoneId: number;
    label: string;
    seats: number;
    x: number;
    y: number;
    width: number;
    height: number;
    rotation: number;
    shape: TableShape;
    isAuxiliary: boolean;
    sort: number;
    deleted: boolean;
};

export type FloorElementType = 'bar' | 'wall' | 'door' | 'window' | 'column' | 'plant' | 'kitchen' | 'toilet' | 'stairs' | 'label';

export type FloorElement = {
    id: number;
    zoneId: number;
    type: FloorElementType;
    label: string | null;
    x: number;
    y: number;
    width: number;
    height: number;
    rotation: number;
    color: string | null;
    sort: number;
    deleted: boolean;
};

export type Destination = { id: number; name: T9n; code: string; mode: 'printer' | 'screen' | 'both'; printerIds: number[] };
export type Printer = { id: number; name: string; type: string; active: boolean; destinationIds: number[]; isTicketPrinter?: boolean; paperWidth?: number };

export type Category = {
    id: number;
    parentId: number | null;
    name: T9n;
    color: string | null;
    destinationId: number | null;
    isMenu: boolean;
    active: boolean;
    sort: number;
    modifierGroupIds: number[];
    deleted: boolean;
};

export type Product = {
    id: number;
    categoryId: number | null;
    name: T9n;
    price: number;
    vatRate: number;
    photoUrl: string | null;
    color: string | null;
    allergens: string[];
    destinationId: number | null;
    active: boolean;
    soldOut: boolean;
    sort: number;
    orderCount: number;
    modifierGroupIds: number[];
    deleted: boolean;
};

export type Modifier = { id: number; name: T9n; priceDelta: number };
export type ModifierGroup = { id: number; name: T9n; multiple: boolean; required: boolean; sort: number; modifiers: Modifier[]; deleted: boolean };

export type SetMenuItem = { id: number; productId: number | null; name: T9n; destinationId: number | null; supplement: number };
export type SetMenuSection = { id: number; name: T9n; choices: number; course: number | null; items: SetMenuItem[] };
export type SetMenu = {
    id: number;
    name: T9n;
    includes: T9n | null;
    price: number;
    vatRate: number;
    color: string | null;
    scheduleType: 'always' | 'weekdays' | 'dates';
    weekdays: number[];
    startsOn: string | null;
    endsOn: string | null;
    active: boolean;
    sort: number;
    sections: SetMenuSection[];
    deleted: boolean;
};

export type LineModifier = { id: number | null; name: Translatable; priceDelta: number };

export type OrderLine = {
    uuid: string;
    parentUuid: string | null;
    productId: number | null;
    setMenuId: number | null;
    destinationId: number | null;
    name: T9n;
    quantity: number;
    unitPrice: number;
    vatRate: number;
    modifiers: LineModifier[];
    note: string | null;
    course: number | null;
    discountType: 'percent' | 'amount' | null;
    discountValue: number;
    discountReason: string | null;
    voided: boolean;
    voidReason: string | null;
    paidQuantity: number;
    createdBy: number | null;
    sentAt: string | null;
};

export type OrderStatus = 'open' | 'bill_requested' | 'paid' | 'cancelled' | 'merged';

export type Order = {
    uuid: string;
    tableId: number | null;
    status: OrderStatus;
    guests: number | null;
    label: string | null;
    openedBy: number | null;
    openedAt: string;
    billRequestedAt: string | null;
    closedAt: string | null;
    mergedIntoUuid: string | null;
    reservationUuid: string | null;
    lines: OrderLine[];
};

export type KitchenStatus = 'pending' | 'preparing' | 'ready' | 'served';

export type KitchenTicket = {
    uuid: string;
    orderUuid: string;
    destinationId: number;
    tableLabel: string | null;
    course: number | null;
    held: boolean;
    status: KitchenStatus;
    createdBy: number | null;
    sentAt: string;
    startedAt: string | null;
    readyAt: string | null;
    servedAt: string | null;
    items: { lineUuid: string | null; name: T9n; quantity: number; modifiers: LineModifier[]; note: string | null; voided: boolean }[];
};

export type ReservationStatus = 'confirmed' | 'seated' | 'completed' | 'cancelled' | 'no_show';

export type Reservation = {
    uuid: string;
    name: string;
    phone: string | null;
    partySize: number;
    reservedAt: string;
    durationMinutes: number;
    zoneId: number | null;
    tableId: number | null;
    notes: string | null;
    status: ReservationStatus;
    source: string;
    orderUuid: string | null;
    deleted: boolean;
};

export type VatRow = { rate: number; base: number; vat: number; total: number };

export type TicketSummary = {
    uuid: string;
    fullNumber: string;
    number: number;
    issuedAt: string;
    tableLabel: string | null;
    total: number;
    discountTotal: number;
    surchargeAmount: number;
    vatBreakdown: VatRow[];
    payments: { method: 'cash' | 'card'; amount: number }[];
    local?: boolean;
    document?: PrintDocument;
};

export type CashierState = {
    series: { code: string; lastNumber: number } | null;
    session: { uuid: string; openedAt: string; openingFloat: number; openedBy: number | null } | null;
    tickets: TicketSummary[];
};

export type Shift = { id: number; date: string; startTime: string; endTime: string; breakMinutes: number; name: string | null; color: string; notes: string | null };

export type ClockType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';
export type ClockEvent = { type: ClockType; at: string; source?: string; syncedLate?: boolean; corrected?: boolean; pending?: boolean };
export type ClockState = { state: 'out' | 'in' | 'break'; since: string | null; todayMinutes: number; entries: ClockEvent[] };

export type Snapshot = {
    full: boolean;
    serverTime: string;
    cursor: string;
    me: Me;
    device: DeviceInfo | null;
    settings: Settings;
    staff: Staff[];
    zones: Zone[];
    tables: DiningTable[];
    floorElements?: FloorElement[];
    destinations: Destination[];
    printers: Printer[];
    categories: Category[];
    products: Product[];
    modifierGroups: ModifierGroup[];
    setMenus: SetMenu[];
    orders: Order[];
    kitchenTickets: KitchenTicket[];
    reservations: Reservation[];
    cashier: CashierState | null;
    myShifts: Shift[];
    myClock: ClockState;
    clock: { secret: string; seconds: number; url: string } | null;
};

export type OutboxStatus = 'pending' | 'sending' | 'rejected';

export type Operation = {
    uuid: string;
    type: string;
    payload: Record<string, unknown>;
    createdAt: string;
    operatorId: number;
    status: OutboxStatus;
    attempts: number;
    reason?: string;
    seq: number;
};

export type PrintLine =
    | { type: 'text'; text: string; align?: 'left' | 'center' | 'right'; bold?: boolean; size?: 1 | 2; invert?: boolean }
    | { type: 'row'; left: string; right: string; bold?: boolean; size?: 1 | 2 }
    | { type: 'divider'; char?: string }
    | { type: 'qr'; data: string; caption?: string }
    | { type: 'image'; url: string }
    | { type: 'feed'; lines?: number }
    | { type: 'cut' };

export type PrintDocument = { width: number; lines: PrintLine[] };

export type PrintJobPayload = { uuid: string; kind: string; title: string; printerId: number | null; document: PrintDocument };
