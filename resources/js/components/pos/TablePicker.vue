<script setup lang="ts">
import { computed, ref } from 'vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { activeOrderForTable, sorted } from '@/pos/store';
import type { DiningTable } from '@/pos/types';

const props = defineProps<{ title: string; onlyOccupied?: boolean; exclude?: number | null }>();
const emit = defineEmits<{ close: []; pick: [table: DiningTable] }>();

const open = ref(true);
const zoneId = ref<number | null>(sorted.zones.value[0]?.id ?? null);

const tables = computed(() =>
    sorted.tables.value.filter((table) => table.zoneId === zoneId.value && table.id !== props.exclude && (!props.onlyOccupied || activeOrderForTable(table.id))),
);

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="max-h-[90svh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ t('floor.selectTarget') }}</DialogDescription>
            </DialogHeader>
            <div class="flex gap-2 overflow-x-auto">
                <button
                    v-for="zone in sorted.zones.value"
                    :key="zone.id"
                    type="button"
                    class="h-10 shrink-0 rounded-lg px-3 text-sm font-semibold"
                    :class="zoneId === zone.id ? 'bg-[#00056a] text-white' : 'bg-slate-100'"
                    @click="zoneId = zone.id"
                >
                    {{ tr(zone.name) }}
                </button>
            </div>
            <div class="grid grid-cols-4 gap-2 sm:grid-cols-6">
                <button
                    v-for="table in tables"
                    :key="table.id"
                    type="button"
                    class="flex h-16 items-center justify-center rounded-xl border-2 text-lg font-bold"
                    :class="activeOrderForTable(table.id) ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200 bg-white'"
                    @click="emit('pick', table); close()"
                >
                    {{ table.label }}
                </button>
            </div>
            <p v-if="!tables.length" class="text-center text-sm text-slate-500">{{ t('common.empty') }}</p>
        </DialogContent>
    </Dialog>
</template>
