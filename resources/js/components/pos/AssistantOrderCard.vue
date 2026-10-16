<script setup lang="ts">
import { CircleAlert, Loader2, Send, Utensils } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { OrderPreview } from '@/assistant/api';
import { t, tr } from '@/i18n';
import { assistantOrders, pendingLines, sendAssistantOrder } from '@/pos/assistantOrders';
import { formatMoney } from '@/pos/money';
import { go } from '@/pos/router';

/** What the assistant put on a table: lines, anything it could not resolve, and quick actions. */
const props = defineProps<{ order: OrderPreview }>();

const sending = ref(false);
const status = computed(() => assistantOrders[props.order.id] ?? null);
const waiting = computed(() => pendingLines(props.order.tableId));

async function send(): Promise<void> {
    sending.value = true;

    try {
        await sendAssistantOrder(props.order);
        toast.success(t('order.sentOk'));
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl border bg-white shadow-sm dark:bg-slate-900">
        <div class="flex items-center gap-3 bg-emerald-600 px-4 py-3 text-white">
            <Utensils class="size-5 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-lg font-bold">{{ t('order.table', { label: order.tableLabel }) }}</p>
                <p class="text-xs opacity-90">
                    <template v-if="order.zone">{{ tr(order.zone) }} · </template>
                    {{ status === 'sent' ? t('assistantOrder.sent') : status === 'failed' ? t('assistantOrder.failed') : t('assistantOrder.added') }}
                </p>
            </div>
        </div>
        <ul class="divide-y">
            <li v-for="(line, index) in order.lines" :key="index" class="flex items-start gap-2 px-4 py-2.5">
                <span class="w-7 shrink-0 text-right text-lg font-bold">{{ line.quantity }}</span>
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ tr(line.name) }}</p>
                    <p v-for="m in line.modifiers" :key="m.id" class="text-xs text-slate-500">+ {{ tr(m.name) }}</p>
                    <p v-if="line.note" class="text-xs font-medium text-amber-700">! {{ line.note }}</p>
                </div>
                <span v-if="line.unitPrice !== null" class="shrink-0 font-semibold tabular-nums">{{ formatMoney((line.unitPrice + line.modifiers.reduce((s, m) => s + m.priceDelta, 0)) * line.quantity) }}</span>
                <span v-else class="shrink-0 rounded bg-amber-100 px-1.5 text-xs font-semibold text-amber-800">{{ t('order.unpriced') }}</span>
            </li>
        </ul>
        <ul v-if="order.problems.length" class="space-y-1 border-t bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
            <li v-for="(problem, index) in order.problems" :key="index" class="flex gap-2"><CircleAlert class="mt-0.5 size-4 shrink-0" /> {{ problem }}</li>
        </ul>
        <div class="grid grid-cols-2 gap-2 border-t bg-slate-50 p-2 dark:bg-slate-800/50">
            <button type="button" class="flex h-12 items-center justify-center gap-2 rounded-xl border bg-white text-sm font-semibold text-slate-700 active:scale-[0.98] dark:bg-slate-900 dark:text-slate-100" @click="go(`/taula/${order.tableId}`)">
                {{ t('assistantOrder.open') }}
            </button>
            <button
                type="button"
                class="flex h-12 items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white active:scale-[0.98] disabled:opacity-50"
                :disabled="sending || status === 'sent' || !waiting"
                @click="send"
            >
                <Loader2 v-if="sending" class="size-5 animate-spin" />
                <Send v-else class="size-5" />
                {{ status === 'sent' || !waiting ? t('assistantOrder.nothingToSend') : t('assistantOrder.send', { count: waiting }) }}
            </button>
        </div>
    </div>
</template>
