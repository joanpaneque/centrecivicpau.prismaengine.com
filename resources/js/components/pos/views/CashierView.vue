<script setup lang="ts">
import { BellRing, FileText, LockKeyhole, Plus, Printer, Receipt, Unlock } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import PrintPreview from '@/components/pos/PrintPreview.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { closeSession, localSummary, openSession, orderLabel, reportDocument, reprint, waiterName } from '@/pos/cashier';
import { formatMoney, parseMoney } from '@/pos/money';
import { orderTotal } from '@/pos/orders';
import { go } from '@/pos/router';
import { sorted, state } from '@/pos/store';
import type { PrintDocument, TicketSummary } from '@/pos/types';

const DENOMINATIONS = [50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

const isCashier = computed(() => state.device?.type === 'cashier');
const session = computed(() => state.cashier?.session ?? null);
const orders = computed(() => sorted.activeOrders.value);
const tickets = computed(() => [...(state.cashier?.tickets ?? [])].sort((a, b) => b.number - a.number));
const summary = computed(() => localSummary());

const openingFloat = ref('');
const opening = ref(false);

const preview = ref<{ title: string; document: PrintDocument } | null>(null);
const closing = ref(false);
const counts = ref<Record<number, string>>({});
const notes = ref('');
const submitting = ref(false);

const counted = computed(() => DENOMINATIONS.reduce((sum, d) => sum + d * Math.max(0, Number.parseInt(counts.value[d] || '0', 10) || 0), 0));
const difference = computed(() => counted.value - summary.value.expected_cash);

function elapsed(iso: string): string {
    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));

    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function denominationLabel(cents: number): string {
    return cents >= 100 ? `${cents / 100} €` : `${cents} c`;
}

async function open(): Promise<void> {
    opening.value = true;

    try {
        await openSession(parseMoney(openingFloat.value));
        openingFloat.value = '';
    } finally {
        opening.value = false;
    }
}

function showTicket(ticket: TicketSummary): void {
    if (ticket.document) {
        preview.value = { title: ticket.fullNumber, document: ticket.document };
    } else {
        toast.info(t('cashier.reprintOnlineOnly'));
    }
}

function showX(): void {
    preview.value = { title: t('cashier.xReport'), document: reportDocument(true) };
}

async function printPreview(): Promise<void> {
    if (!preview.value) {
        return;
    }

    await reprint(preview.value.title, preview.value.document);
    toast.success(t('cashier.sentToPrinter'));
}

function startClose(): void {
    counts.value = {};
    notes.value = '';
    closing.value = true;
}

async function confirmClose(): Promise<void> {
    submitting.value = true;

    try {
        const cashCount: Record<string, number> = {};

        for (const d of DENOMINATIONS) {
            const qty = Number.parseInt(counts.value[d] || '0', 10) || 0;

            if (qty > 0) {
                cashCount[String(d)] = qty;
            }
        }

        const document = await closeSession(cashCount, counted.value, notes.value);
        closing.value = false;
        preview.value = { title: t('cashier.zReport'), document };
        toast.success(t('cashier.sessionClosedOk'));
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="h-full overflow-y-auto">
        <div v-if="!isCashier" class="mx-auto mt-16 max-w-md rounded-2xl bg-white p-8 text-center shadow dark:bg-slate-900">
            <Receipt class="mx-auto mb-3 size-10 text-slate-400" />
            <p>{{ t('cashier.notCashierDevice') }}</p>
        </div>

        <div v-else-if="!session" class="mx-auto mt-16 max-w-sm rounded-2xl bg-white p-8 shadow dark:bg-slate-900">
            <div class="mb-6 flex items-center gap-3">
                <LockKeyhole class="size-8 text-[#00056a] dark:text-blue-300" />
                <div>
                    <h2 class="text-xl font-semibold">{{ t('cashier.sessionClosed') }}</h2>
                    <p class="text-sm text-slate-500">{{ state.device?.name }}</p>
                </div>
            </div>
            <label class="mb-1 block text-sm font-medium">{{ t('cashier.openingFloat') }}</label>
            <Input v-model="openingFloat" inputmode="decimal" placeholder="0,00" class="mb-4 h-12 text-lg" @keyup.enter="open" />
            <Button class="h-12 w-full bg-[#00056a] text-base" :disabled="opening" @click="open">
                <Unlock class="size-5" /> {{ t('cashier.openSession') }}
            </Button>
        </div>

        <div v-else class="grid gap-4 p-3 lg:grid-cols-[1fr_380px] lg:p-4">
            <section>
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold">{{ t('cashier.openOrders') }}</h2>
                    <Button variant="outline" class="h-11" @click="go('/comanda/nova')"><Plus class="size-5" /> {{ t('cashier.quickSale') }}</Button>
                </div>

                <p v-if="!orders.length" class="rounded-xl bg-white p-10 text-center text-slate-500 dark:bg-slate-900">{{ t('cashier.noOpenOrders') }}</p>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                    <button
                        v-for="order in orders"
                        :key="order.uuid"
                        type="button"
                        class="flex flex-col items-start gap-1 rounded-xl border-2 bg-white p-4 text-left shadow-sm transition active:scale-[0.98] dark:bg-slate-900"
                        :class="order.status === 'bill_requested' ? 'border-amber-400' : 'border-transparent'"
                        @click="go(`/caixa/${order.uuid}`)"
                    >
                        <div class="flex w-full items-center justify-between gap-2">
                            <span class="truncate text-lg font-bold">{{ orderLabel(order) }}</span>
                            <BellRing v-if="order.status === 'bill_requested'" class="size-5 shrink-0 text-amber-500" />
                        </div>
                        <span class="text-xs text-slate-500">{{ waiterName(order) }} · {{ elapsed(order.openedAt) }}</span>
                        <span class="mt-2 text-xl font-semibold tabular-nums">{{ formatMoney(orderTotal(order)) }}</span>
                    </button>
                </div>
            </section>

            <aside class="space-y-4">
                <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-slate-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-semibold">{{ state.device?.name }}</h3>
                        <span class="text-xs text-slate-500">{{ t('admin.cashSessions.openedAt') }} {{ time(session.openedAt) }}</span>
                    </div>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>{{ t('cashier.openingFloat') }}</dt><dd class="tabular-nums">{{ formatMoney(session.openingFloat) }}</dd></div>
                        <div class="flex justify-between"><dt>{{ t('cashier.cash') }}</dt><dd class="tabular-nums">{{ formatMoney(summary.by_method.cash ?? 0) }}</dd></div>
                        <div class="flex justify-between"><dt>{{ t('cashier.card') }}</dt><dd class="tabular-nums">{{ formatMoney(summary.by_method.card ?? 0) }}</dd></div>
                        <div class="flex justify-between border-t pt-1 font-semibold"><dt>{{ t('common.total') }} ({{ summary.tickets }})</dt><dd class="tabular-nums">{{ formatMoney(summary.total) }}</dd></div>
                        <div class="flex justify-between text-slate-500"><dt>{{ t('cashier.expected') }}</dt><dd class="tabular-nums">{{ formatMoney(summary.expected_cash) }}</dd></div>
                    </dl>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <Button variant="outline" class="h-11" @click="showX"><FileText class="size-4" /> {{ t('cashier.xReport') }}</Button>
                        <Button variant="destructive" class="h-11" @click="startClose"><LockKeyhole class="size-4" /> {{ t('cashier.closeSession') }}</Button>
                    </div>
                </div>

                <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-slate-900">
                    <h3 class="mb-2 font-semibold">{{ t('cashier.sessionTickets') }}</h3>
                    <p v-if="!tickets.length" class="py-4 text-center text-sm text-slate-500">{{ t('cashier.noTickets') }}</p>
                    <ul class="max-h-[50vh] divide-y overflow-y-auto">
                        <li v-for="ticket in tickets" :key="ticket.uuid">
                            <button type="button" class="flex w-full items-center justify-between gap-2 py-2 text-left text-sm" @click="showTicket(ticket)">
                                <span>
                                    <span class="block font-mono font-medium">{{ ticket.fullNumber }}</span>
                                    <span class="text-xs text-slate-500">{{ time(ticket.issuedAt) }} · {{ ticket.tableLabel ?? '-' }} · {{ ticket.payments.map((p) => t(`cashier.${p.method}`)).join(' + ') }}</span>
                                </span>
                                <span class="font-semibold tabular-nums">{{ formatMoney(ticket.total) }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>

        <Dialog :open="!!preview" @update:open="(v) => !v && (preview = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ preview?.title }}</DialogTitle>
                </DialogHeader>
                <div class="flex justify-center"><PrintPreview v-if="preview" :document="preview.document" /></div>
                <DialogFooter>
                    <Button variant="outline" @click="preview = null">{{ t('common.close') }}</Button>
                    <Button class="bg-[#00056a]" @click="printPreview"><Printer class="size-4" /> {{ t('cashier.reprint') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="closing">
            <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ t('cashier.closeSession') }}</DialogTitle>
                </DialogHeader>
                <p class="text-sm font-medium">{{ t('cashier.cashCount') }}</p>
                <div class="grid grid-cols-3 gap-2">
                    <label v-for="d in DENOMINATIONS" :key="d" class="flex items-center gap-2 rounded-lg border p-2 text-sm">
                        <span class="w-12 shrink-0 font-medium">{{ denominationLabel(d) }}</span>
                        <Input v-model="counts[d]" inputmode="numeric" class="h-9" placeholder="0" />
                    </label>
                </div>
                <dl class="space-y-1 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800">
                    <div class="flex justify-between"><dt>{{ t('cashier.counted') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(counted) }}</dd></div>
                    <div class="flex justify-between"><dt>{{ t('cashier.expected') }}</dt><dd class="tabular-nums">{{ formatMoney(summary.expected_cash) }}</dd></div>
                    <div class="flex justify-between font-semibold" :class="difference === 0 ? 'text-emerald-600' : 'text-red-600'">
                        <dt>{{ t('cashier.difference') }}</dt><dd class="tabular-nums">{{ formatMoney(difference) }}</dd>
                    </div>
                </dl>
                <Input v-model="notes" :placeholder="t('cashier.notes')" maxlength="250" />
                <p v-if="orders.length" class="rounded-lg bg-amber-50 p-2 text-sm text-amber-800">{{ t('cashier.openOrdersWarning', { count: orders.length }) }}</p>
                <DialogFooter>
                    <Button variant="outline" @click="closing = false">{{ t('common.cancel') }}</Button>
                    <Button variant="destructive" :disabled="submitting" @click="confirmClose">{{ t('cashier.closeSession') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
