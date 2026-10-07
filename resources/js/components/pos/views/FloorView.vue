<script setup lang="ts">
import { ChefHat, List, Map as MapIcon, Pencil, ShoppingBag, Trash2, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FloorCanvas from '@/components/pos/FloorCanvas.vue';
import TableEditor from '@/components/pos/TableEditor.vue';
import { Button } from '@/components/ui/button';
import { t, tr } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { formatMoney } from '@/pos/money';
import { orderTotal, tableStatus } from '@/pos/orders';
import { go } from '@/pos/router';
import { sorted, state } from '@/pos/store';
import { pull, sync } from '@/pos/sync';
import type { DiningTable } from '@/pos/types';

const zoneId = ref<number | null>(Number(localStorage.getItem('tpv-zone')) || null);
const mode = ref<'map' | 'list'>((localStorage.getItem('tpv-floor-mode') as 'map' | 'list') || 'map');
const editing = ref(false);
const editingTable = ref<DiningTable | null>(null);
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | null = null;

watch(zoneId, (value) => localStorage.setItem('tpv-zone', String(value ?? '')));
watch(mode, (value) => localStorage.setItem('tpv-floor-mode', value));

const zones = sorted.zones;
const zone = computed(() => (zoneId.value && state.zones[zoneId.value]) || zones.value[0] || null);
const tables = computed(() => sorted.tables.value.filter((table) => table.zoneId === zone.value?.id));
const elements = computed(() => sorted.floorElements.value.filter((element) => element.zoneId === zone.value?.id));
const isAdmin = computed(() => state.me?.role === 'admin');

const statuses = computed(() => {
    void now.value;
    const map: Record<number, ReturnType<typeof tableStatus>> = {};

    for (const table of tables.value) {
        map[table.id] = tableStatus(table.id);
    }

    return map;
});

const zoneCounts = computed(() => {
    const counts: Record<number, number> = {};

    for (const order of sorted.activeOrders.value) {
        const table = order.tableId ? state.tables[order.tableId] : null;

        if (table) {
            counts[table.zoneId] = (counts[table.zoneId] ?? 0) + 1;
        }
    }

    return counts;
});

const quickOrders = computed(() => sorted.activeOrders.value.filter((o) => !o.tableId));

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30000);
});

onBeforeUnmount(() => {
    if (clock) {
        clearInterval(clock);
    }
});

function elapsed(iso: string): string {
    const minutes = Math.max(0, Math.floor((now.value - new Date(iso).getTime()) / 60000));

    return minutes >= 60 ? `${Math.floor(minutes / 60)}h ${minutes % 60}′` : `${minutes}′`;
}

function open(table: DiningTable): void {
    if (editing.value) {
        editingTable.value = table;

        return;
    }

    go(`/taula/${table.id}`);
}

function colorClasses(status: string): string {
    switch (status) {
        case 'occupied':
            return 'bg-[#00056a] text-white border-[#00056a]';
        case 'bill':
            return 'bg-amber-400 text-amber-950 border-amber-500';
        case 'reserved':
            return 'bg-violet-100 text-violet-900 border-violet-400 border-dashed';
        default:
            return 'bg-white text-slate-700 border-slate-300';
    }
}

async function removeAuxiliary(): Promise<void> {
    if (!zone.value || !confirm(t('common.areYouSure'))) {
        return;
    }

    try {
        const response = await api<{ removed: number; kept: number }>('DELETE', `/tpv/api/zones/${zone.value.id}/auxiliary`);
        toast.success(t('floor.removeAuxiliaryDone', response));
        await pull();
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    }
}

function toggleEdit(): void {
    if (!sync.online && !editing.value) {
        toast.error(t('floor.editorOffline'));

        return;
    }

    editing.value = !editing.value;

    if (editing.value) {
        mode.value = 'map';
    }
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex shrink-0 items-center gap-2 overflow-x-auto border-b bg-white px-2 py-2 dark:bg-slate-900">
            <button
                v-for="z in zones"
                :key="z.id"
                type="button"
                class="flex h-11 shrink-0 items-center gap-2 rounded-xl px-4 text-sm font-semibold transition"
                :class="zone?.id === z.id ? 'bg-[#00056a] text-white shadow' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200'"
                @click="zoneId = z.id"
            >
                {{ tr(z.name) }}
                <span v-if="zoneCounts[z.id]" class="rounded-full bg-white/25 px-1.5 text-xs" :class="zone?.id === z.id ? '' : 'bg-[#00056a]/10 text-[#00056a]'">{{ zoneCounts[z.id] }}</span>
            </button>

            <div class="ml-auto flex shrink-0 items-center gap-1">
                <Button variant="outline" class="h-11" @click="go('/comanda/nova')">
                    <ShoppingBag class="size-5" />
                    <span class="hidden sm:inline">{{ t('floor.quickSale') }}</span>
                </Button>
                <Button variant="ghost" size="icon" class="size-11" :title="mode === 'map' ? t('floor.list') : t('floor.map')" @click="mode = mode === 'map' ? 'list' : 'map'">
                    <List v-if="mode === 'map'" class="size-5" />
                    <MapIcon v-else class="size-5" />
                </Button>
                <Button v-if="isAdmin" :variant="editing ? 'default' : 'outline'" class="h-11" :class="editing ? 'bg-amber-500 hover:bg-amber-600' : ''" @click="toggleEdit">
                    <X v-if="editing" class="size-5" />
                    <Pencil v-else class="size-5" />
                    <span class="hidden sm:inline">{{ editing ? t('floor.exitEdit') : t('floor.editMode') }}</span>
                </Button>
            </div>
        </div>

        <div v-if="quickOrders.length && !editing" class="flex shrink-0 gap-2 overflow-x-auto border-b bg-white px-2 py-2">
            <button
                v-for="order in quickOrders"
                :key="order.uuid"
                type="button"
                class="flex shrink-0 items-center gap-2 rounded-lg border border-[#00056a]/30 bg-[#00056a]/5 px-3 py-1.5 text-sm"
                @click="go(`/comanda/${order.uuid}`)"
            >
                <ShoppingBag class="size-4 text-[#00056a]" />
                <span class="font-semibold">{{ order.label || t('floor.quickSaleLabel') }}</span>
                <span class="text-slate-500">{{ formatMoney(orderTotal(order)) }}</span>
            </button>
        </div>

        <FloorCanvas
            v-if="mode === 'map'"
            :zone-id="zone?.id ?? null"
            :tables="tables"
            :elements="elements"
            :statuses="statuses"
            :editing="editing"
            :now="now"
            @open="open"
            @edit-table="(table) => (editingTable = table)"
        >
            <template #toolbar>
                <Button size="sm" variant="outline" class="h-10 text-red-700" @click="removeAuxiliary"><Trash2 class="size-4" /> {{ t('floor.removeAuxiliary') }}</Button>
            </template>
        </FloorCanvas>

        <div v-else class="min-h-0 flex-1 overflow-y-auto p-3">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <button
                    v-for="table in tables"
                    :key="table.id"
                    type="button"
                    class="relative flex h-24 flex-col items-start justify-between rounded-xl border-2 p-3 text-left"
                    :class="colorClasses(statuses[table.id]?.status)"
                    @click="open(table)"
                >
                    <span class="text-2xl font-bold">{{ table.label }}</span>
                    <span v-if="statuses[table.id]?.order" class="text-sm font-semibold">{{ formatMoney(statuses[table.id].total) }} · {{ elapsed(statuses[table.id].order!.openedAt) }}</span>
                    <span v-else class="text-sm opacity-70">{{ t(`floor.${statuses[table.id]?.status === 'reserved' ? 'reserved' : 'free'}`) }}</span>
                    <ChefHat v-if="statuses[table.id]?.ready" class="absolute top-2 right-2 size-5 text-emerald-500" />
                </button>
            </div>
        </div>

        <div class="flex shrink-0 items-center justify-center gap-4 border-t bg-white px-3 py-1.5 text-xs text-slate-600">
            <span class="flex items-center gap-1"><span class="size-3 rounded border border-slate-300 bg-white" /> {{ t('floor.free') }}</span>
            <span class="flex items-center gap-1"><span class="size-3 rounded bg-[#00056a]" /> {{ t('floor.occupied') }}</span>
            <span class="flex items-center gap-1"><span class="size-3 rounded bg-amber-400" /> {{ t('floor.billRequested') }}</span>
            <span class="flex items-center gap-1"><span class="size-3 rounded border border-dashed border-violet-400 bg-violet-100" /> {{ t('floor.reserved') }}</span>
            <span class="flex items-center gap-1"><ChefHat class="size-3 text-emerald-600" /> {{ t('floor.ready') }}</span>
        </div>

        <TableEditor v-if="editingTable" :table="editingTable" @close="editingTable = null" />
    </div>
</template>
