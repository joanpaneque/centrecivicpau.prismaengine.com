<script setup lang="ts">
import { ArrowRightLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import FloorCanvas from '@/components/pos/FloorCanvas.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { tableStatus } from '@/pos/orders';
import { activeOrderForTable, sorted, state } from '@/pos/store';
import type { DiningTable } from '@/pos/types';

/**
 * Pick the new table on the floor plan (any room), then confirm. Occupied tables cannot be
 * chosen: joining two bills is a separate action ("Junta").
 */
const props = defineProps<{ fromTableId: number }>();
const emit = defineEmits<{ move: [table: DiningTable]; close: [] }>();

const open = ref(true);
const from = computed(() => state.tables[props.fromTableId]);
const zoneId = ref<number | null>(from.value?.zoneId ?? sorted.zones.value[0]?.id ?? null);
const target = ref<DiningTable | null>(null);
const now = Date.now();

const tables = computed(() => sorted.tables.value.filter((table) => table.zoneId === zoneId.value));
const elements = computed(() => sorted.floorElements.value.filter((element) => element.zoneId === zoneId.value));
const statuses = computed(() => {
    const map: Record<number, ReturnType<typeof tableStatus>> = {};

    for (const table of tables.value) {
        map[table.id] = tableStatus(table.id);
    }

    return map;
});

function pick(table: DiningTable): void {
    if (table.id === props.fromTableId) {
        return;
    }

    if (activeOrderForTable(table.id)) {
        toast.error(t('order.tableOccupied', { label: table.label }));

        return;
    }

    target.value = table;
}

function confirm(): void {
    if (target.value) {
        emit('move', target.value);
    }

    close();
}

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="flex h-[94svh] max-w-6xl flex-col gap-3 sm:max-w-6xl">
            <DialogHeader class="shrink-0">
                <DialogTitle>{{ t('order.moveTitle', { label: from?.label ?? '' }) }}</DialogTitle>
                <DialogDescription>{{ t('order.moveHelp') }}</DialogDescription>
            </DialogHeader>
            <div class="flex shrink-0 gap-2 overflow-x-auto">
                <button
                    v-for="zone in sorted.zones.value"
                    :key="zone.id"
                    type="button"
                    class="h-11 shrink-0 rounded-lg px-4 text-sm font-semibold"
                    :class="zoneId === zone.id ? 'bg-[#00056a] text-white' : 'bg-slate-100'"
                    @click="zoneId = zone.id"
                >
                    {{ tr(zone.name) }}
                </button>
            </div>
            <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border">
                <FloorCanvas :zone-id="zoneId" :tables="tables" :elements="elements" :statuses="statuses" :editing="false" :now="now" @open="pick" />
            </div>
        </DialogContent>
    </Dialog>

    <Dialog :open="!!target" @update:open="(v) => !v && (target = null)">
        <DialogContent v-if="target" class="max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><ArrowRightLeft class="size-5" /> {{ t('order.moveTable') }}</DialogTitle>
                <DialogDescription class="text-base text-slate-700">
                    {{ t('order.moveConfirm', { from: from?.label ?? '', to: target.label }) }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="outline" class="h-12 flex-1" @click="target = null">{{ t('common.cancel') }}</Button>
                <Button class="h-12 flex-1 bg-[#00056a]" @click="confirm">{{ t('common.accept') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
