<script setup lang="ts">
import {
    ArrowDownToLine,
    ArrowUpToLine,
    BellRing,
    CalendarClock,
    ChefHat,
    Circle,
    CookingPot,
    Copy,
    Leaf,
    Martini,
    Pencil,
    Plus,
    RectangleHorizontal,
    RotateCw,
    Square,
    Toilet,
    Trash2,
    Users,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { formatMoney } from '@/pos/money';
import type { tableStatus } from '@/pos/orders';
import { remove, state, upsert } from '@/pos/store';
import type { DiningTable, FloorElement, FloorElementType, TableShape } from '@/pos/types';

const CANVAS_W = 1000;
const CANVAS_H = 680;

type Status = ReturnType<typeof tableStatus>;
type Kind = 'table' | 'element';
type Selection = { kind: Kind; id: number };
type Geometry = { x: number; y: number; width: number; height: number; rotation: number };
type Handle = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w';

const props = defineProps<{
    zoneId: number | null;
    tables: DiningTable[];
    elements: FloorElement[];
    statuses: Record<number, Status>;
    editing: boolean;
    now: number;
}>();

const emit = defineEmits<{ open: [table: DiningTable]; editTable: [table: DiningTable] }>();

const ELEMENT_TYPES: FloorElementType[] = ['bar', 'wall', 'door', 'window', 'column', 'plant', 'kitchen', 'toilet', 'stairs', 'label'];
const TABLE_SHAPES: { shape: TableShape; icon: typeof Square }[] = [
    { shape: 'square', icon: Square },
    { shape: 'round', icon: Circle },
    { shape: 'rect', icon: RectangleHorizontal },
    { shape: 'stool', icon: Circle },
];
const COLORS = ['#8b5e3c', '#334155', '#64748b', '#0f766e', '#15803d', '#b45309', '#be123c', '#00056a', '#7c3aed'];
const HANDLES: Handle[] = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

const container = ref<HTMLElement | null>(null);
const canvas = ref<HTMLElement | null>(null);
const scale = ref(1);
const selection = ref<Selection | null>(null);
const busy = ref(false);

// --- Layout -----------------------------------------------------------------------------

let observer: ResizeObserver | null = null;

function resize(): void {
    if (!container.value) {
        return;
    }

    const { clientWidth, clientHeight } = container.value;
    scale.value = Math.max(0.2, Math.min(clientWidth / CANVAS_W, clientHeight / CANVAS_H, 1.6));
}

onMounted(() => {
    observer = new ResizeObserver(resize);

    if (container.value) {
        observer.observe(container.value);
    }

    resize();
    window.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener('keydown', onKey);
    endGesture(false);
});

watch(
    () => props.editing,
    (editing) => {
        if (!editing) {
            selection.value = null;
        }
    },
);

watch(
    () => props.zoneId,
    () => (selection.value = null),
);

// --- Geometry helpers -------------------------------------------------------------------

function rad(deg: number): number {
    return (deg * Math.PI) / 180;
}

function normalizeAngle(deg: number): number {
    return ((Math.round(deg) % 360) + 360) % 360;
}

function bounds(g: Geometry): { width: number; height: number } {
    const r = rad(g.rotation ?? 0);
    const c = Math.abs(Math.cos(r));
    const s = Math.abs(Math.sin(r));

    return { width: g.width * c + g.height * s, height: g.width * s + g.height * c };
}

/** Keep the rotated bounding box inside the canvas. */
function clampPosition(g: Geometry, x: number, y: number): { x: number; y: number } {
    const box = bounds(g);
    const cx = Math.min(CANVAS_W - box.width / 2, Math.max(box.width / 2, x + g.width / 2));
    const cy = Math.min(CANVAS_H - box.height / 2, Math.max(box.height / 2, y + g.height / 2));

    return { x: Math.round(cx - g.width / 2), y: Math.round(cy - g.height / 2) };
}

function snap(value: number, step: number): number {
    return Math.round(value / step) * step;
}

function itemOf(sel: Selection | null): (DiningTable | FloorElement) | null {
    if (!sel) {
        return null;
    }

    return (sel.kind === 'table' ? state.tables[sel.id] : state.floorElements[sel.id]) ?? null;
}

function geometryOf(item: Geometry): Geometry {
    return { x: item.x, y: item.y, width: item.width, height: item.height, rotation: item.rotation ?? 0 };
}

function shapeStyle(g: Geometry): Record<string, string> {
    return {
        left: `${g.x}px`,
        top: `${g.y}px`,
        width: `${g.width}px`,
        height: `${g.height}px`,
        transform: g.rotation ? `rotate(${g.rotation}deg)` : '',
    };
}

/** Upright box centred on the item, used for labels that must not rotate. */
function contentStyle(g: Geometry): Record<string, string> {
    const box = bounds(g);

    return {
        left: `${g.x + g.width / 2 - box.width / 2}px`,
        top: `${g.y + g.height / 2 - box.height / 2}px`,
        width: `${box.width}px`,
        height: `${box.height}px`,
    };
}

const selected = computed(() => itemOf(selection.value));
const selectedIsRound = computed(() => selection.value?.kind === 'table' && ['round', 'stool'].includes((selected.value as DiningTable | null)?.shape ?? ''));
const handleSize = computed(() => 28 / scale.value);

// --- Gestures (pointer events: finger, pen or mouse) ------------------------------------

type Gesture = {
    kind: 'move' | 'resize' | 'rotate';
    pointerId: number;
    target: Selection;
    handle: Handle | null;
    startX: number;
    startY: number;
    origin: Geometry;
    changed: boolean;
};

let gesture: Gesture | null = null;

function startGesture(event: PointerEvent, target: Selection, kind: Gesture['kind'], handle: Handle | null = null): void {
    if (!props.editing || gesture) {
        return;
    }

    const item = itemOf(target);

    if (!item) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    selection.value = target;
    gesture = { kind, pointerId: event.pointerId, target, handle, startX: event.clientX, startY: event.clientY, origin: geometryOf(item), changed: false };
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerUp);
}

function onPointerMove(event: PointerEvent): void {
    const g = gesture;

    if (!g || event.pointerId !== g.pointerId) {
        return;
    }

    const item = itemOf(g.target);

    if (!item) {
        return;
    }

    const dx = (event.clientX - g.startX) / scale.value;
    const dy = (event.clientY - g.startY) / scale.value;

    if (!g.changed && Math.hypot(dx, dy) < 3) {
        return;
    }

    g.changed = true;

    if (g.kind === 'move') {
        const pos = clampPosition(g.origin, snap(g.origin.x + dx, 10), snap(g.origin.y + dy, 10));
        item.x = pos.x;
        item.y = pos.y;
    } else if (g.kind === 'resize' && g.handle) {
        applyResize(item, g, dx, dy);
    } else if (g.kind === 'rotate') {
        applyRotate(item, event);
    }
}

function applyResize(item: Geometry, g: Gesture, dx: number, dy: number): void {
    const isTable = g.target.kind === 'table';
    const min = isTable ? 40 : 8;
    const max = isTable ? 400 : 1000;
    const step = isTable ? 10 : 5;
    const theta = rad(g.origin.rotation);
    const cos = Math.cos(theta);
    const sin = Math.sin(theta);
    const localX = dx * cos + dy * sin;
    const localY = -dx * sin + dy * cos;
    const sx = g.handle!.includes('e') ? 1 : g.handle!.includes('w') ? -1 : 0;
    const sy = g.handle!.includes('s') ? 1 : g.handle!.includes('n') ? -1 : 0;

    let width = sx ? Math.min(max, Math.max(min, snap(g.origin.width + sx * localX, step))) : g.origin.width;
    let height = sy ? Math.min(max, Math.max(min, snap(g.origin.height + sy * localY, step))) : g.origin.height;

    if (selectedIsRound.value) {
        const size = sx && sy ? Math.max(width, height) : sx ? width : height;
        width = height = size;
    }

    // Keep the opposite side fixed: move the centre by half the growth, in the rotated frame.
    const growX = sx ? (sx * (width - g.origin.width)) / 2 : 0;
    const growY = sy ? (sy * (height - g.origin.height)) / 2 : 0;
    const cx = g.origin.x + g.origin.width / 2 + growX * cos - growY * sin;
    const cy = g.origin.y + g.origin.height / 2 + growX * sin + growY * cos;

    item.width = width;
    item.height = height;
    item.x = Math.round(cx - width / 2);
    item.y = Math.round(cy - height / 2);
}

function applyRotate(item: Geometry, event: PointerEvent): void {
    const rect = canvas.value?.getBoundingClientRect();

    if (!rect) {
        return;
    }

    const cx = rect.left + (item.x + item.width / 2) * scale.value;
    const cy = rect.top + (item.y + item.height / 2) * scale.value;
    const angle = (Math.atan2(event.clientY - cy, event.clientX - cx) * 180) / Math.PI + 90;
    item.rotation = normalizeAngle(snap(angle, 15));
}

function onPointerUp(event: PointerEvent): void {
    if (gesture && event.pointerId === gesture.pointerId) {
        endGesture(true);
    }
}

function endGesture(save: boolean): void {
    const g = gesture;
    gesture = null;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerUp);

    if (g && save && g.changed) {
        const item = itemOf(g.target);

        if (item) {
            const pos = clampPosition(item, item.x, item.y);
            item.x = pos.x;
            item.y = pos.y;
            void saveGeometry(g.target, g.origin);
        }
    }
}

function backgroundDown(event: PointerEvent): void {
    if (props.editing && event.target === event.currentTarget) {
        selection.value = null;
    }
}

// --- Persistence ------------------------------------------------------------------------

async function saveGeometry(target: Selection, origin: Geometry | null): Promise<void> {
    const item = itemOf(target);

    if (!item) {
        return;
    }

    const row = { id: item.id, ...geometryOf(item) };

    try {
        await api('POST', '/tpv/api/layout', target.kind === 'table' ? { tables: [row] } : { elements: [row] });
        upsert(target.kind === 'table' ? 'tables' : 'floorElements', item as never);
    } catch (e) {
        if (origin) {
            Object.assign(item, origin);
        }

        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    }
}

let nudgeTimer: ReturnType<typeof setTimeout> | null = null;
let nudgeOrigin: Geometry | null = null;

function nudge(dx: number, dy: number): void {
    const sel = selection.value;
    const item = itemOf(sel);

    if (!sel || !item) {
        return;
    }

    nudgeOrigin ??= geometryOf(item);
    const pos = clampPosition(item, item.x + dx, item.y + dy);
    item.x = pos.x;
    item.y = pos.y;

    if (nudgeTimer) {
        clearTimeout(nudgeTimer);
    }

    nudgeTimer = setTimeout(() => {
        void saveGeometry(sel, nudgeOrigin);
        nudgeOrigin = null;
    }, 400);
}

function onKey(event: KeyboardEvent): void {
    if (!props.editing || !selection.value || (event.target as HTMLElement)?.closest('input, textarea, [role="dialog"]')) {
        return;
    }

    const step = event.shiftKey ? 1 : 10;
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };

    if (moves[event.key]) {
        event.preventDefault();
        nudge(...moves[event.key]);
    } else if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        void deleteSelected();
    } else if (event.key === 'Escape') {
        selection.value = null;
    }
}

function freeSpot(width: number, height: number): { x: number; y: number } {
    const occupied = [...props.tables, ...props.elements].map((item) => {
        const box = bounds(geometryOf(item));

        return { x: item.x + item.width / 2 - box.width / 2, y: item.y + item.height / 2 - box.height / 2, width: box.width, height: box.height };
    });

    for (let y = 20; y <= CANVAS_H - height - 10; y += 20) {
        for (let x = 20; x <= CANVAS_W - width - 10; x += 20) {
            if (!occupied.some((o) => x < o.x + o.width + 10 && x + width + 10 > o.x && y < o.y + o.height + 10 && y + height + 10 > o.y)) {
                return { x, y };
            }
        }
    }

    return { x: 20, y: 20 };
}

async function addTable(shape: TableShape, isAuxiliary = false): Promise<void> {
    if (!props.zoneId || busy.value) {
        return;
    }

    const width = shape === 'rect' ? 160 : shape === 'stool' ? 70 : 90;
    const height = shape === 'stool' ? 70 : 90;
    busy.value = true;

    try {
        const response = await api<{ table: DiningTable }>('POST', '/tpv/api/tables', { zoneId: props.zoneId, shape, isAuxiliary, ...freeSpot(width, height) });
        upsert('tables', response.table);
        selection.value = { kind: 'table', id: response.table.id };
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    } finally {
        busy.value = false;
    }
}

const DEFAULT_SIZES: Record<FloorElementType, [number, number]> = {
    bar: [300, 60],
    wall: [300, 12],
    door: [80, 80],
    window: [120, 12],
    column: [40, 40],
    plant: [50, 50],
    kitchen: [200, 120],
    toilet: [100, 100],
    stairs: [100, 140],
    label: [160, 40],
};

async function addElement(type: FloorElementType): Promise<void> {
    if (!props.zoneId || busy.value) {
        return;
    }

    const [width, height] = DEFAULT_SIZES[type];
    busy.value = true;

    try {
        const response = await api<{ element: FloorElement }>('POST', '/tpv/api/elements', { zoneId: props.zoneId, type, width, height, ...freeSpot(width, height) });
        upsert('floorElements', response.element);
        selection.value = { kind: 'element', id: response.element.id };
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    } finally {
        busy.value = false;
    }
}

async function duplicateSelected(): Promise<void> {
    const sel = selection.value;
    const item = itemOf(sel);

    if (!sel || !item || !props.zoneId || busy.value) {
        return;
    }

    const pos = clampPosition(item, item.x + 30, item.y + 30);
    busy.value = true;

    try {
        if (sel.kind === 'table') {
            const table = item as DiningTable;
            const response = await api<{ table: DiningTable }>('POST', '/tpv/api/tables', {
                zoneId: props.zoneId,
                shape: table.shape,
                seats: table.seats,
                isAuxiliary: table.isAuxiliary,
                width: table.width,
                height: table.height,
                rotation: table.rotation ?? 0,
                ...pos,
            });
            upsert('tables', response.table);
            selection.value = { kind: 'table', id: response.table.id };
        } else {
            const element = item as FloorElement;
            const response = await api<{ element: FloorElement }>('POST', '/tpv/api/elements', {
                zoneId: props.zoneId,
                type: element.type,
                label: element.label,
                color: element.color,
                width: element.width,
                height: element.height,
                rotation: element.rotation,
                ...pos,
            });
            upsert('floorElements', response.element);
            selection.value = { kind: 'element', id: response.element.id };
        }
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    } finally {
        busy.value = false;
    }
}

function rotateSelected(step = 90): void {
    const sel = selection.value;
    const item = itemOf(sel);

    if (!sel || !item) {
        return;
    }

    const origin = geometryOf(item);
    item.rotation = normalizeAngle((item.rotation ?? 0) + step);
    const pos = clampPosition(item, item.x, item.y);
    item.x = pos.x;
    item.y = pos.y;
    void saveGeometry(sel, origin);
}

async function deleteSelected(): Promise<void> {
    const sel = selection.value;

    if (!sel || !confirm(t('common.areYouSure'))) {
        return;
    }

    try {
        await api('DELETE', sel.kind === 'table' ? `/tpv/api/tables/${sel.id}` : `/tpv/api/elements/${sel.id}`);
        remove(sel.kind === 'table' ? 'tables' : 'floorElements', sel.id);
        selection.value = null;
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    }
}

async function reorderSelected(front: boolean): Promise<void> {
    const sel = selection.value;

    if (sel?.kind !== 'element') {
        return;
    }

    const element = state.floorElements[sel.id];
    const sorts = props.elements.map((e) => e.sort);
    const sort = front ? Math.max(0, ...sorts) + 1 : Math.min(0, ...sorts) - 1;

    try {
        const response = await api<{ element: FloorElement }>('PATCH', `/tpv/api/elements/${sel.id}`, { sort: Math.max(0, sort) });
        upsert('floorElements', { ...element, ...response.element });
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    }
}

function editSelected(): void {
    const sel = selection.value;

    if (!sel) {
        return;
    }

    if (sel.kind === 'table') {
        emit('editTable', state.tables[sel.id]);
    } else {
        openElementDialog(state.floorElements[sel.id]);
    }
}

// --- Element dialog ---------------------------------------------------------------------

const elementDialog = ref<FloorElement | null>(null);
const elementForm = reactive({ label: '', color: null as string | null, width: 100, height: 100, rotation: 0 });

function openElementDialog(element: FloorElement): void {
    Object.assign(elementForm, { label: element.label ?? '', color: element.color, width: element.width, height: element.height, rotation: element.rotation ?? 0 });
    elementDialog.value = element;
}

async function saveElement(): Promise<void> {
    const element = elementDialog.value;

    if (!element) {
        return;
    }

    try {
        const response = await api<{ element: FloorElement }>('PATCH', `/tpv/api/elements/${element.id}`, {
            label: elementForm.label.trim() || null,
            color: elementForm.color,
            width: Math.max(8, Math.round(Number(elementForm.width) || element.width)),
            height: Math.max(8, Math.round(Number(elementForm.height) || element.height)),
            rotation: normalizeAngle(Number(elementForm.rotation) || 0),
        });
        upsert('floorElements', response.element);
        elementDialog.value = null;
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    }
}

// --- Rendering helpers ------------------------------------------------------------------

function tableClasses(table: DiningTable): string[] {
    const status = props.statuses[table.id]?.status;
    const colors =
        status === 'occupied'
            ? 'bg-[#00056a] border-[#00056a]'
            : status === 'bill'
              ? 'bg-amber-400 border-amber-500'
              : status === 'reserved'
                ? 'bg-violet-100 border-violet-400 border-dashed'
                : 'bg-white border-slate-300';

    return [colors, table.shape === 'round' || table.shape === 'stool' ? 'rounded-full' : 'rounded-xl', table.isAuxiliary ? 'ring-2 ring-sky-300 ring-offset-1' : ''];
}

function tableTextClass(table: DiningTable): string {
    const status = props.statuses[table.id]?.status;

    return status === 'occupied' ? 'text-white' : status === 'bill' ? 'text-amber-950' : status === 'reserved' ? 'text-violet-900' : 'text-slate-700';
}

function elapsed(iso: string): string {
    const minutes = Math.max(0, Math.floor((props.now - new Date(iso).getTime()) / 60000));

    return minutes >= 60 ? `${Math.floor(minutes / 60)}h ${minutes % 60}′` : `${minutes}′`;
}

function elementLabel(element: FloorElement): string {
    return element.label ?? (['bar', 'kitchen', 'toilet', 'stairs', 'label'].includes(element.type) ? t(`floor.elements.${element.type}`) : '');
}

function elementStyle(element: FloorElement): Record<string, string> {
    const style = shapeStyle(element);

    if (element.color && element.type !== 'label') {
        style.backgroundColor = element.color;
    }

    if (element.color && element.type === 'label') {
        style.color = element.color;
    }

    return style;
}

function elementClasses(element: FloorElement): string {
    switch (element.type) {
        case 'bar':
            return 'rounded-lg bg-[#8b5e3c] text-white shadow-md';
        case 'wall':
            return 'rounded-sm bg-slate-700';
        case 'door':
            return '';
        case 'window':
            return 'border-2 border-sky-400 bg-sky-100';
        case 'column':
            return 'rounded-sm bg-slate-500 shadow';
        case 'plant':
            return 'rounded-full bg-emerald-600 text-white shadow';
        case 'kitchen':
            return 'rounded-lg border-2 border-dashed border-orange-400 bg-orange-50 text-orange-800';
        case 'toilet':
            return 'rounded-lg border-2 border-slate-300 bg-slate-100 text-slate-600';
        case 'stairs':
            return 'rounded-sm border-2 border-slate-400 bg-[repeating-linear-gradient(0deg,#e2e8f0_0_10px,#cbd5e1_10px_12px)] text-slate-600';
        default:
            return 'text-slate-600 font-semibold';
    }
}

function handleStyle(handle: Handle, g: Geometry): Record<string, string> {
    const s = handleSize.value;
    const left = handle.includes('w') ? -s / 2 : handle.includes('e') ? g.width - s / 2 : g.width / 2 - s / 2;
    const top = handle.includes('n') ? -s / 2 : handle.includes('s') ? g.height - s / 2 : g.height / 2 - s / 2;

    return { left: `${left}px`, top: `${top}px`, width: `${s}px`, height: `${s}px` };
}

function visibleHandles(g: Geometry): Handle[] {
    if (selectedIsRound.value) {
        return ['nw', 'ne', 'se', 'sw'];
    }

    // Thin items (walls, windows) only get the handles that make sense along their length.
    if (g.height * scale.value < 30) {
        return ['w', 'e'];
    }

    if (g.width * scale.value < 30) {
        return ['n', 's'];
    }

    return HANDLES;
}

function onTableClick(table: DiningTable): void {
    if (!props.editing) {
        emit('open', table);
    }
}

defineExpose({ addTable, addElement });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div v-if="editing" class="flex shrink-0 flex-wrap items-center gap-2 border-b bg-amber-50 px-3 py-2 text-sm">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button size="sm" class="h-10 bg-[#00056a]" :disabled="busy"><Plus class="size-4" /> {{ t('floor.addTable') }}</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-56">
                    <DropdownMenuItem v-for="item in TABLE_SHAPES" :key="item.shape" class="h-11" @select="addTable(item.shape)">
                        <component :is="item.icon" class="size-4" :class="item.shape === 'stool' ? 'scale-75' : ''" /> {{ t(`floor.shapes.${item.shape}`) }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem class="h-11" @select="addTable('square', true)"><Plus class="size-4" /> {{ t('floor.addAuxiliary') }}</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button size="sm" variant="outline" class="h-10" :disabled="busy"><Plus class="size-4" /> {{ t('floor.addElement') }}</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-56">
                    <DropdownMenuLabel>{{ t('floor.elementsTitle') }}</DropdownMenuLabel>
                    <DropdownMenuItem v-for="type in ELEMENT_TYPES" :key="type" class="h-11" @select="addElement(type)">
                        <span class="inline-block size-4 rounded-sm" :class="elementClasses({ type } as FloorElement)" />
                        {{ t(`floor.elements.${type}`) }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <slot name="toolbar" />

            <span class="ml-auto hidden text-xs text-amber-900 lg:inline">{{ t('floor.editHelp') }}</span>
        </div>

        <div ref="container" class="relative min-h-0 flex-1 overflow-hidden" :class="editing ? 'touch-none' : ''">
            <div
                ref="canvas"
                class="absolute top-0 left-1/2 origin-top rounded-xl bg-slate-50"
                :class="editing ? 'bg-[linear-gradient(#e2e8f0_1px,transparent_1px),linear-gradient(90deg,#e2e8f0_1px,transparent_1px)] bg-[size:20px_20px] ring-2 ring-amber-300' : ''"
                :style="{ width: `${CANVAS_W}px`, height: `${CANVAS_H}px`, transform: `translateX(-50%) scale(${scale})` }"
                @pointerdown="backgroundDown"
            >
                <!-- Decorative elements (behind the tables) -->
                <div
                    v-for="element in elements"
                    :key="`e${element.id}`"
                    class="absolute flex items-center justify-center gap-1 overflow-hidden text-xs select-none"
                    :class="[elementClasses(element), editing ? 'cursor-move' : 'pointer-events-none']"
                    :style="elementStyle(element)"
                    @pointerdown="startGesture($event, { kind: 'element', id: element.id }, 'move')"
                    @dblclick="editing && openElementDialog(element)"
                >
                    <svg v-if="element.type === 'door'" class="absolute inset-0 size-full overflow-visible" :viewBox="`0 0 ${element.width} ${element.height}`" preserveAspectRatio="none">
                        <line x1="0" :y1="element.height" :x2="element.width" :y2="element.height" stroke="#94a3b8" stroke-width="4" />
                        <line x1="2" :y1="element.height" x2="2" y2="0" :stroke="element.color ?? '#475569'" stroke-width="4" />
                        <path :d="`M 2 0 A ${element.width - 2} ${element.height} 0 0 1 ${element.width} ${element.height}`" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="6 4" />
                    </svg>
                    <span v-else-if="element.type === 'window'" class="h-0.5 w-full bg-sky-400" />
                    <template v-else>
                        <Martini v-if="element.type === 'bar' && element.height >= 30" class="size-4 shrink-0 opacity-80" />
                        <Leaf v-if="element.type === 'plant'" class="size-1/2" />
                        <CookingPot v-if="element.type === 'kitchen'" class="size-5 shrink-0" />
                        <Toilet v-if="element.type === 'toilet'" class="size-5 shrink-0" />
                        <span v-if="elementLabel(element) && element.height >= 20" class="truncate px-1" :class="element.type === 'label' ? 'text-base' : 'font-semibold'">{{ elementLabel(element) }}</span>
                    </template>
                </div>

                <!-- Tables: the shape rotates, the content stays upright -->
                <template v-for="table in tables" :key="`t${table.id}`">
                    <button
                        type="button"
                        class="absolute border-2 shadow-sm transition-colors"
                        :class="[tableClasses(table), editing ? 'cursor-move' : 'active:brightness-95']"
                        :style="shapeStyle(table)"
                        :aria-label="table.label"
                        @click="onTableClick(table)"
                        @pointerdown="startGesture($event, { kind: 'table', id: table.id }, 'move')"
                        @dblclick="editing && emit('editTable', table)"
                    />
                    <div class="pointer-events-none absolute flex flex-col items-center justify-center p-1 text-center" :class="tableTextClass(table)" :style="contentStyle(table)">
                        <span class="text-xl leading-none font-bold">{{ table.label }}</span>
                        <template v-if="statuses[table.id]?.order">
                            <span class="mt-0.5 text-xs font-semibold opacity-90">{{ formatMoney(statuses[table.id].total) }}</span>
                            <span class="flex items-center gap-1 text-[11px] opacity-80">
                                <Users v-if="statuses[table.id].order?.guests" class="size-3" />
                                {{ statuses[table.id].order?.guests || '' }}
                                {{ elapsed(statuses[table.id].order!.openedAt) }}
                            </span>
                        </template>
                        <span v-else-if="statuses[table.id]?.reservation" class="mt-0.5 flex items-center gap-1 text-[11px] font-semibold">
                            <CalendarClock class="size-3" />
                            {{ new Date(statuses[table.id].reservation!.reservedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                        </span>
                        <span v-else-if="table.height >= 60" class="text-[11px] opacity-60">{{ table.seats }} <Users class="inline size-3" /></span>
                        <span v-if="statuses[table.id]?.ready" class="absolute -top-2 -right-2 flex size-7 items-center justify-center rounded-full bg-emerald-500 text-white shadow ring-2 ring-white">
                            <ChefHat class="size-4" />
                        </span>
                        <span v-if="statuses[table.id]?.status === 'bill'" class="absolute -top-2 -left-2 flex size-7 items-center justify-center rounded-full bg-amber-600 text-white shadow ring-2 ring-white">
                            <BellRing class="size-4" />
                        </span>
                    </div>
                </template>

                <!-- Selection frame with resize and rotate handles -->
                <div v-if="editing && selection && selected" class="pointer-events-none absolute" :style="shapeStyle(selected)">
                    <div class="absolute -inset-1 rounded-md border-2 border-dashed border-amber-500" />
                    <div class="absolute left-1/2 w-0.5 -translate-x-1/2 bg-amber-500" :style="{ top: `${-handleSize * 1.6}px`, height: `${handleSize * 1.6}px` }" />
                    <button
                        type="button"
                        class="pointer-events-auto absolute flex items-center justify-center rounded-full border-2 border-white bg-amber-500 text-white shadow-md"
                        :style="{ left: `${selected.width / 2 - handleSize / 2}px`, top: `${-handleSize * 2.2}px`, width: `${handleSize}px`, height: `${handleSize}px` }"
                        :aria-label="t('floor.rotate')"
                        @pointerdown="startGesture($event, selection, 'rotate')"
                    >
                        <RotateCw :style="{ width: `${handleSize * 0.6}px`, height: `${handleSize * 0.6}px` }" />
                    </button>
                    <button
                        v-for="handle in visibleHandles(selected)"
                        :key="handle"
                        type="button"
                        class="pointer-events-auto absolute rounded-full border-2 border-amber-500 bg-white shadow-md"
                        :style="handleStyle(handle, selected)"
                        :aria-label="t('floor.size')"
                        @pointerdown="startGesture($event, selection, 'resize', handle)"
                    />
                </div>
            </div>

            <!-- Floating toolbar for the selected item -->
            <div
                v-if="editing && selection && selected"
                class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-1 rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-slate-200 dark:bg-slate-900"
            >
                <span class="px-2 text-sm font-semibold">
                    {{ selection.kind === 'table' ? `${t('reservations.table')} ${(selected as DiningTable).label}` : elementLabel(selected as FloorElement) || t(`floor.elements.${(selected as FloorElement).type}`) }}
                </span>
                <Button variant="ghost" class="h-11" @click="editSelected"><Pencil class="size-5" /><span class="hidden sm:inline">{{ t('common.edit') }}</span></Button>
                <Button variant="ghost" class="h-11" @click="rotateSelected(selection.kind === 'table' ? 90 : 45)"><RotateCw class="size-5" /><span class="hidden sm:inline">{{ t('floor.rotate') }}</span></Button>
                <Button variant="ghost" class="h-11" :disabled="busy" @click="duplicateSelected"><Copy class="size-5" /><span class="hidden sm:inline">{{ t('common.duplicate') }}</span></Button>
                <template v-if="selection.kind === 'element'">
                    <Button variant="ghost" size="icon" class="size-11" :title="t('floor.bringFront')" @click="reorderSelected(true)"><ArrowUpToLine class="size-5" /></Button>
                    <Button variant="ghost" size="icon" class="size-11" :title="t('floor.sendBack')" @click="reorderSelected(false)"><ArrowDownToLine class="size-5" /></Button>
                </template>
                <Button variant="ghost" size="icon" class="size-11 text-red-600" :title="t('common.delete')" @click="deleteSelected"><Trash2 class="size-5" /></Button>
                <Button variant="ghost" size="icon" class="size-11" @click="selection = null"><X class="size-5" /></Button>
            </div>
        </div>

        <Dialog :open="!!elementDialog" @update:open="(v) => !v && (elementDialog = null)">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ elementDialog ? t(`floor.elements.${elementDialog.type}`) : '' }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ t('floor.editMode') }}</DialogDescription>
                </DialogHeader>
                <div class="grid gap-4">
                    <label class="grid gap-1.5 text-sm font-medium">
                        {{ t('floor.elementLabel') }}
                        <Input v-model="elementForm.label" maxlength="40" class="h-11" :placeholder="elementDialog ? t(`floor.elements.${elementDialog.type}`) : ''" />
                    </label>
                    <div class="grid gap-1.5 text-sm font-medium">
                        {{ t('common.color') }}
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="size-9 rounded-full border-2 bg-[conic-gradient(#fff_0_25%,#e2e8f0_0_50%,#fff_0_75%,#e2e8f0_0)] bg-[size:12px_12px]" :class="!elementForm.color ? 'border-[#00056a] ring-2 ring-[#00056a]/30' : 'border-slate-200'" @click="elementForm.color = null" />
                            <button
                                v-for="color in COLORS"
                                :key="color"
                                type="button"
                                class="size-9 rounded-full border-2"
                                :class="elementForm.color === color ? 'border-[#00056a] ring-2 ring-[#00056a]/30' : 'border-white'"
                                :style="{ backgroundColor: color }"
                                @click="elementForm.color = color"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <label class="grid gap-1.5 text-sm">↔ <Input v-model.number="elementForm.width" type="number" min="8" max="1000" step="5" /></label>
                        <label class="grid gap-1.5 text-sm">↕ <Input v-model.number="elementForm.height" type="number" min="8" max="1000" step="5" /></label>
                        <label class="grid gap-1.5 text-sm">{{ t('floor.rotation') }} <Input v-model.number="elementForm.rotation" type="number" min="0" max="359" step="15" /></label>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="elementDialog = null">{{ t('common.cancel') }}</Button>
                    <Button class="bg-[#00056a]" @click="saveElement">{{ t('common.save') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
