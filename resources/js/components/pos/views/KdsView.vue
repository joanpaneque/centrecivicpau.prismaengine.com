<script setup lang="ts">
import { Check, ChefHat, History, Pause, Play, Undo2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { t, tr } from '@/i18n';
import { state } from '@/pos/store';
import { enqueue } from '@/pos/sync';
import type { KitchenStatus, KitchenTicket } from '@/pos/types';

const STORAGE_KEY = 'tpv-kds-destination';

const screens = computed(() => Object.values(state.destinations).filter((d) => d.mode !== 'printer'));
const destinationId = ref<number | null>(Number(localStorage.getItem(STORAGE_KEY)) || null);
const showRecall = ref(false);
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;

watch(destinationId, (value) => (value ? localStorage.setItem(STORAGE_KEY, String(value)) : localStorage.removeItem(STORAGE_KEY)));

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 15000);
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }
});

const visible = computed(() =>
    Object.values(state.kitchenTickets).filter((k) => (!destinationId.value || k.destinationId === destinationId.value) && k.items.some((i) => !i.voided)),
);

const active = computed(() =>
    visible.value
        .filter((k) => k.status !== 'served')
        .sort((a, b) => Number(a.held) - Number(b.held) || a.sentAt.localeCompare(b.sentAt)),
);

const recall = computed(() =>
    visible.value
        .filter((k) => k.status === 'served')
        .sort((a, b) => (b.servedAt ?? '').localeCompare(a.servedAt ?? ''))
        .slice(0, 12),
);

const counts = computed(() => ({
    pending: active.value.filter((k) => k.status === 'pending' && !k.held).length,
    preparing: active.value.filter((k) => k.status === 'preparing').length,
    ready: active.value.filter((k) => k.status === 'ready').length,
}));

function minutes(ticket: KitchenTicket): number {
    return Math.max(0, Math.floor((now.value - new Date(ticket.sentAt).getTime()) / 60000));
}

function ageClass(ticket: KitchenTicket): string {
    if (ticket.held) {
        return 'bg-slate-400';
    }

    if (ticket.status === 'ready') {
        return 'bg-emerald-600';
    }

    const age = minutes(ticket);

    return age >= 20 ? 'bg-red-600' : age >= 10 ? 'bg-amber-500' : 'bg-[#00056a]';
}

function setStatus(ticket: KitchenTicket, status: KitchenStatus): void {
    void enqueue('kds.status', { ticketUuid: ticket.uuid, status });
}

function previous(status: KitchenStatus): KitchenStatus {
    return status === 'served' ? 'ready' : status === 'ready' ? 'preparing' : 'pending';
}

function destinationName(id: number): string {
    return tr(state.destinations[id]?.name);
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b bg-white px-3 py-2 dark:bg-slate-900">
            <ChefHat class="size-5 text-[#00056a] dark:text-blue-300" />
            <button
                type="button"
                class="h-10 rounded-lg px-3 text-sm font-medium"
                :class="!destinationId ? 'bg-[#00056a] text-white' : 'bg-slate-100 dark:bg-slate-800'"
                @click="destinationId = null"
            >
                {{ t('kds.allDestinations') }}
            </button>
            <button
                v-for="d in screens"
                :key="d.id"
                type="button"
                class="h-10 rounded-lg px-3 text-sm font-medium"
                :class="destinationId === d.id ? 'bg-[#00056a] text-white' : 'bg-slate-100 dark:bg-slate-800'"
                @click="destinationId = d.id"
            >
                {{ tr(d.name) }}
            </button>
            <div class="ml-auto flex items-center gap-3 text-sm">
                <span class="rounded bg-slate-200 px-2 py-1 dark:bg-slate-700">{{ t('kds.pending') }}: {{ counts.pending }}</span>
                <span class="rounded bg-amber-100 px-2 py-1 text-amber-900">{{ t('kds.preparing') }}: {{ counts.preparing }}</span>
                <span class="rounded bg-emerald-100 px-2 py-1 text-emerald-900">{{ t('kds.ready') }}: {{ counts.ready }}</span>
                <Button variant="outline" class="h-10" @click="showRecall = !showRecall"><History class="size-4" /> {{ t('kds.recall') }}</Button>
            </div>
        </div>

        <div v-if="showRecall" class="flex gap-2 overflow-x-auto border-b bg-slate-50 p-2 dark:bg-slate-900">
            <p v-if="!recall.length" class="p-2 text-sm text-slate-500">{{ t('common.empty') }}</p>
            <div v-for="ticket in recall" :key="ticket.uuid" class="flex shrink-0 items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm shadow-sm dark:bg-slate-800">
                <span class="font-bold">{{ ticket.tableLabel ?? '-' }}</span>
                <span class="text-slate-500">{{ ticket.items.filter((i) => !i.voided).length }} · {{ destinationName(ticket.destinationId) }}</span>
                <Button size="sm" variant="ghost" @click="setStatus(ticket, 'ready')"><Undo2 class="size-4" /></Button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-3">
            <div v-if="!active.length" class="flex h-full flex-col items-center justify-center gap-3 text-slate-400">
                <ChefHat class="size-16" />
                <p class="text-lg">{{ t('kds.empty') }}</p>
            </div>

            <div class="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] items-start gap-3">
                <article
                    v-for="ticket in active"
                    :key="ticket.uuid"
                    class="overflow-hidden rounded-xl bg-white shadow dark:bg-slate-900"
                    :class="[ticket.held ? 'opacity-60' : '', ticket.status === 'preparing' ? 'ring-4 ring-amber-400' : '']"
                >
                    <header class="flex items-center justify-between px-3 py-2 text-white" :class="ageClass(ticket)">
                        <span class="text-2xl font-bold">{{ ticket.tableLabel ?? '-' }}</span>
                        <span class="text-right text-sm leading-tight">
                            <span class="block font-semibold">{{ minutes(ticket) }} min</span>
                            <span class="block opacity-80">{{ destinationName(ticket.destinationId) }}</span>
                        </span>
                    </header>
                    <div v-if="ticket.held" class="flex items-center gap-1 bg-slate-100 px-3 py-1 text-xs font-semibold uppercase dark:bg-slate-800">
                        <Pause class="size-3" /> {{ t('kds.held') }}
                    </div>
                    <div v-else-if="ticket.course" class="bg-slate-100 px-3 py-1 text-xs font-semibold uppercase dark:bg-slate-800">{{ t(`order.courses.${ticket.course}`) }}</div>
                    <ul class="divide-y px-3">
                        <li v-for="(item, index) in ticket.items" :key="item.lineUuid ?? index" class="py-2" :class="item.voided ? 'text-red-500 line-through' : ''">
                            <p class="text-lg font-semibold"><span class="tabular-nums">{{ item.quantity }}×</span> {{ tr(item.name) }}</p>
                            <p v-for="m in item.modifiers" :key="tr(m.name)" class="pl-6 text-sm text-slate-600 dark:text-slate-300">+ {{ tr(m.name) }}</p>
                            <p v-if="item.note" class="pl-6 text-sm font-bold text-red-600">! {{ item.note }}</p>
                        </li>
                    </ul>
                    <footer class="flex gap-2 border-t p-2">
                        <Button v-if="ticket.status !== 'pending'" variant="outline" size="icon" class="size-12" @click="setStatus(ticket, previous(ticket.status))"><Undo2 class="size-5" /></Button>
                        <Button v-if="ticket.status === 'pending'" class="h-12 flex-1 bg-amber-500 text-base hover:bg-amber-600" :disabled="ticket.held" @click="setStatus(ticket, 'preparing')">
                            <Play class="size-5" /> {{ t('kds.start') }}
                        </Button>
                        <Button v-if="ticket.status === 'pending' || ticket.status === 'preparing'" class="h-12 flex-1 bg-emerald-600 text-base hover:bg-emerald-700" @click="setStatus(ticket, 'ready')">
                            <Check class="size-5" /> {{ t('kds.markReady') }}
                        </Button>
                        <Button v-if="ticket.status === 'ready'" class="h-12 flex-1 bg-[#00056a] text-base" @click="setStatus(ticket, 'served')">
                            <Check class="size-5" /> {{ t('kds.markServed') }}
                        </Button>
                    </footer>
                </article>
            </div>
        </div>
    </div>
</template>
