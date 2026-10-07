<script setup lang="ts">
import { Armchair, CalendarClock, ChevronLeft, ChevronRight, Clock, Pencil, Phone, Plus, StickyNote, Users } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import TablePicker from '@/components/pos/TablePicker.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { locale, t, tr } from '@/i18n';
import { uuid } from '@/pos/crypto';
import { draftFor } from '@/pos/orders';
import { go } from '@/pos/router';
import { activeOrderForTable, sorted, state } from '@/pos/store';
import { enqueue } from '@/pos/sync';
import type { DiningTable, Reservation, ReservationStatus } from '@/pos/types';

const STATUS_CLASS: Record<ReservationStatus, string> = {
    confirmed: 'bg-blue-100 text-blue-900',
    seated: 'bg-emerald-100 text-emerald-900',
    completed: 'bg-slate-200 text-slate-700',
    cancelled: 'bg-red-100 text-red-800',
    no_show: 'bg-amber-100 text-amber-900',
};

function isoDay(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

const day = ref(isoDay(new Date()));
const showCancelled = ref(false);

const reservations = computed(() =>
    Object.values(state.reservations)
        .filter((r) => !r.deleted && isoDay(new Date(r.reservedAt)) === day.value && (showCancelled.value || (r.status !== 'cancelled' && r.status !== 'no_show')))
        .sort((a, b) => a.reservedAt.localeCompare(b.reservedAt)),
);

const totals = computed(() => {
    const active = reservations.value.filter((r) => r.status === 'confirmed' || r.status === 'seated');

    return { count: active.length, people: active.reduce((sum, r) => sum + r.partySize, 0) };
});

const dayLabel = computed(() =>
    new Date(`${day.value}T12:00:00`).toLocaleDateString(locale.value === 'es' ? 'es-ES' : 'ca-ES', { weekday: 'long', day: 'numeric', month: 'long' }),
);

function shiftDay(delta: number): void {
    const date = new Date(`${day.value}T12:00:00`);
    date.setDate(date.getDate() + delta);
    day.value = isoDay(date);
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function tableLabel(r: Reservation): string {
    if (r.tableId && state.tables[r.tableId]) {
        return `${t('reservations.table')} ${state.tables[r.tableId].label}`;
    }

    return r.zoneId && state.zones[r.zoneId] ? tr(state.zones[r.zoneId].name) : t('reservations.anyTable');
}

const editing = ref(false);
const form = reactive({ uuid: '', name: '', phone: '', partySize: 2, reservedAt: '', durationMinutes: 90, zoneId: null as number | null, tableId: null as number | null, notes: '' });

const zoneTables = computed(() => sorted.tables.value.filter((table) => !form.zoneId || table.zoneId === form.zoneId));

function toLocalInput(iso: string): string {
    const d = new Date(iso);

    return `${isoDay(d)}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function openForm(r: Reservation | null = null): void {
    const base = new Date(`${day.value}T${r ? '00:00' : '21:00'}:00`);
    Object.assign(form, {
        uuid: r?.uuid ?? uuid(),
        name: r?.name ?? '',
        phone: r?.phone ?? '',
        partySize: r?.partySize ?? 2,
        reservedAt: r ? toLocalInput(r.reservedAt) : toLocalInput(base.toISOString()),
        durationMinutes: r?.durationMinutes ?? 90,
        zoneId: r?.zoneId ?? null,
        tableId: r?.tableId ?? null,
        notes: r?.notes ?? '',
    });
    editing.value = true;
}

async function save(): Promise<void> {
    if (!form.name.trim() || !form.reservedAt) {
        toast.error(t('reservations.missingFields'));

        return;
    }

    await enqueue('reservation.save', {
        uuid: form.uuid,
        name: form.name.trim(),
        phone: form.phone.trim() || null,
        partySize: Number(form.partySize) || 1,
        reservedAt: new Date(form.reservedAt).toISOString(),
        durationMinutes: Number(form.durationMinutes) || 90,
        zoneId: form.zoneId,
        tableId: form.tableId,
        notes: form.notes.trim() || null,
    });
    day.value = form.reservedAt.slice(0, 10);
    editing.value = false;
    toast.success(t('common.saved'));
}

function setStatus(r: Reservation, status: ReservationStatus): void {
    void enqueue('reservation.status', { uuid: r.uuid, status });
}

const seating = ref<Reservation | null>(null);

function seat(r: Reservation): void {
    if (r.tableId && !activeOrderForTable(r.tableId)) {
        seatAt(r, state.tables[r.tableId]);

        return;
    }

    seating.value = r;
}

function seatAt(r: Reservation, table: DiningTable): void {
    if (activeOrderForTable(table.id)) {
        toast.error(t('floor.tableBusy'));

        return;
    }

    const draft = draftFor(null, table.id);
    draft.reservationUuid = r.uuid;
    draft.guests = r.partySize;
    seating.value = null;

    if (r.tableId !== table.id) {
        void enqueue('reservation.save', { ...r, tableId: table.id, zoneId: table.zoneId });
    }

    setStatus(r, 'seated');
    go(`/taula/${table.id}`);
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b bg-white px-3 py-2 dark:bg-slate-900">
            <Button variant="outline" size="icon" class="size-10" @click="shiftDay(-1)"><ChevronLeft class="size-5" /></Button>
            <Button variant="outline" class="h-10" @click="day = isoDay(new Date())">{{ t('common.today') }}</Button>
            <Button variant="outline" size="icon" class="size-10" @click="shiftDay(1)"><ChevronRight class="size-5" /></Button>
            <input v-model="day" type="date" class="h-10 rounded-md border bg-transparent px-2 text-sm" />
            <h2 class="ml-1 text-lg font-semibold capitalize">{{ dayLabel }}</h2>
            <span class="text-sm text-slate-500">{{ t('reservations.summary', { count: totals.count, people: totals.people }) }}</span>
            <label class="ml-auto flex items-center gap-2 text-sm">
                <input v-model="showCancelled" type="checkbox" class="size-4" /> {{ t('reservations.showCancelled') }}
            </label>
            <Button class="h-10 bg-[#00056a]" @click="openForm()"><Plus class="size-4" /> {{ t('reservations.new') }}</Button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div v-if="!reservations.length" class="flex h-full flex-col items-center justify-center gap-3 text-slate-400">
                <CalendarClock class="size-16" />
                <p>{{ t('reservations.none') }}</p>
            </div>

            <ul class="mx-auto max-w-4xl space-y-2">
                <li v-for="r in reservations" :key="r.uuid" class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-3 shadow-sm dark:bg-slate-900">
                    <div class="flex w-16 flex-col items-center">
                        <Clock class="size-4 text-slate-400" />
                        <span class="text-lg font-bold tabular-nums">{{ time(r.reservedAt) }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 font-semibold">
                            {{ r.name }}
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="STATUS_CLASS[r.status]">{{ t(`reservations.statuses.${r.status}`) }}</span>
                        </p>
                        <p class="flex flex-wrap items-center gap-x-3 text-sm text-slate-500">
                            <span class="flex items-center gap-1"><Users class="size-4" /> {{ r.partySize }}</span>
                            <span class="flex items-center gap-1"><Armchair class="size-4" /> {{ tableLabel(r) }}</span>
                            <a v-if="r.phone" :href="`tel:${r.phone}`" class="flex items-center gap-1"><Phone class="size-4" /> {{ r.phone }}</a>
                            <span>{{ t(`reservations.sources.${r.source}`) }}</span>
                        </p>
                        <p v-if="r.notes" class="mt-1 flex items-start gap-1 text-sm text-amber-700"><StickyNote class="mt-0.5 size-4 shrink-0" /> {{ r.notes }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="r.status === 'confirmed'" class="h-11 bg-emerald-600 hover:bg-emerald-700" @click="seat(r)">{{ t('reservations.seat') }}</Button>
                        <Button v-if="r.status === 'seated' && r.orderUuid" variant="outline" class="h-11" @click="go(`/comanda/${r.orderUuid}`)">{{ t('order.title') }}</Button>
                        <Button v-if="r.status === 'seated'" variant="outline" class="h-11" @click="setStatus(r, 'completed')">{{ t('reservations.statuses.completed') }}</Button>
                        <Button v-if="r.status === 'confirmed'" variant="outline" class="h-11" @click="setStatus(r, 'no_show')">{{ t('reservations.noShow') }}</Button>
                        <Button v-if="r.status === 'confirmed'" variant="outline" class="h-11 text-red-600" @click="setStatus(r, 'cancelled')">{{ t('common.cancel') }}</Button>
                        <Button v-if="r.status === 'cancelled' || r.status === 'no_show'" variant="outline" class="h-11" @click="setStatus(r, 'confirmed')">{{ t('reservations.restore') }}</Button>
                        <Button variant="ghost" size="icon" class="size-11" @click="openForm(r)"><Pencil class="size-4" /></Button>
                    </div>
                </li>
            </ul>
        </div>

        <Dialog v-model:open="editing">
            <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ state.reservations[form.uuid] ? t('reservations.edit') : t('reservations.new') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="space-y-1 sm:col-span-2">
                        <span class="text-sm font-medium">{{ t('reservations.name') }}</span>
                        <Input v-model="form.name" maxlength="120" />
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ t('reservations.phone') }}</span>
                        <Input v-model="form.phone" type="tel" maxlength="30" />
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ t('reservations.partySize') }}</span>
                        <Input v-model.number="form.partySize" type="number" min="1" max="200" />
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ t('reservations.dateTime') }}</span>
                        <Input v-model="form.reservedAt" type="datetime-local" />
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ t('reservations.duration') }}</span>
                        <Input v-model.number="form.durationMinutes" type="number" min="15" step="15" />
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ t('reservations.zone') }}</span>
                        <select v-model="form.zoneId" class="h-9 w-full rounded-md border bg-transparent px-2 text-sm" @change="form.tableId = null">
                            <option :value="null">{{ t('reservations.anyTable') }}</option>
                            <option v-for="zone in sorted.zones.value" :key="zone.id" :value="zone.id">{{ tr(zone.name) }}</option>
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-sm font-medium">{{ t('reservations.table') }}</span>
                        <select v-model="form.tableId" class="h-9 w-full rounded-md border bg-transparent px-2 text-sm">
                            <option :value="null">{{ t('reservations.anyTable') }}</option>
                            <option v-for="table in zoneTables" :key="table.id" :value="table.id">{{ table.label }} ({{ table.seats }})</option>
                        </select>
                    </label>
                    <label class="space-y-1 sm:col-span-2">
                        <span class="text-sm font-medium">{{ t('reservations.notes') }}</span>
                        <textarea v-model="form.notes" rows="3" maxlength="1000" class="w-full rounded-md border bg-transparent p-2 text-sm" />
                    </label>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="editing = false">{{ t('common.cancel') }}</Button>
                    <Button class="bg-[#00056a]" @click="save">{{ t('common.save') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <TablePicker v-if="seating" :title="t('reservations.chooseTable')" @close="seating = null" @pick="(table) => seatAt(seating!, table)" />
    </div>
</template>
