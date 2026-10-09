<script setup lang="ts">
import { ArrowLeft, Banknote, CheckCircle2, CreditCard, FileText, Minus, Plus, Split, Wallet } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import MoneyKeypad from '@/components/pos/MoneyKeypad.vue';
import PrintPreview from '@/components/pos/PrintPreview.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tr } from '@/i18n';
import { issueTicket, orderLabel, pendingSelection, selectionTotal, splits, surchargeRateFor, waiterName } from '@/pos/cashier';
import type { PaymentInput, Selection } from '@/pos/cashier';
import { formatMoney, parseMoney } from '@/pos/money';
import { childLines } from '@/pos/orders';
import { lineAmount } from '@/pos/print';
import { go, route } from '@/pos/router';
import { followOrder, isActive, state } from '@/pos/store';
import { openTicketPdf } from '@/pos/ticketPdf';
import type { PrintDocument } from '@/pos/types';

type Mode = 'all' | 'items' | 'equal';
type Method = 'cash' | 'card' | 'mixed';

const MODES: Mode[] = ['all', 'items', 'equal'];
const METHODS: { id: Method; icon: typeof Banknote }[] = [
    { id: 'cash', icon: Banknote },
    { id: 'card', icon: CreditCard },
    { id: 'mixed', icon: Split },
];

const order = computed(() => {
    const found = followOrder(route.value.params[0]);

    return found && isActive(found) ? found : null;
});

const mode = ref<Mode>('all');
const method = ref<Method>('cash');
const tendered = ref('');
const cardAmount = ref('');
const customCents = ref<number | null>(null);
const keypadOpen = ref(false);
const keypadValue = ref('');
const chosen = reactive<Record<string, number>>({});
const charging = ref(false);
const printing = ref(false);
const result = ref<{ fullNumber: string; document: PrintDocument; change: number; tip: number; surplus: number; closed: boolean } | null>(null);

const pending = computed(() => (order.value ? pendingSelection(order.value) : []));
const split = computed(() => (order.value ? (splits[order.value.uuid] ?? null) : null));
const parts = ref(split.value?.parts ?? 2);
const paidParts = computed(() => split.value?.paidParts ?? 0);
const partsLeft = computed(() => Math.max(1, parts.value - paidParts.value));
const surchargeRate = computed(() => surchargeRateFor(order.value));

watch(
    () => split.value,
    (value) => {
        if (value) {
            mode.value = 'equal';
            parts.value = value.parts;
        }
    },
    { immediate: true },
);

watch(method, (value) => {
    if (customCents.value !== null && (value === 'cash' || value === 'mixed')) {
        tendered.value = formatMoney(customCents.value, false);
    }
});

const selection = computed<Selection>(() => {
    if (mode.value === 'items') {
        return pending.value.filter(({ line }) => (chosen[line.uuid] ?? 0) > 0).map(({ line }) => ({ line, quantity: chosen[line.uuid] }));
    }

    return pending.value;
});

const full = computed(() => (order.value ? selectionTotal(order.value, pending.value).total : 0));
const remainingBill = computed(() => Math.max(0, full.value - (split.value?.paidAmount ?? 0)));
const equalShare = computed(() => {
    if (mode.value !== 'equal') {
        return 0;
    }

    return partsLeft.value === 1 ? remainingBill.value : Math.round(remainingBill.value / partsLeft.value);
});
const factor = computed(() => {
    if (mode.value !== 'equal') {
        return 1;
    }

    if (!full.value) {
        return 0;
    }

    const consumption = customCents.value !== null ? Math.min(customCents.value, remainingBill.value) : equalShare.value;

    return consumption / full.value;
});
const due = computed(() => (order.value && selection.value.length ? selectionTotal(order.value, selection.value, factor.value).total : 0));
const baseDue = computed(() => (mode.value === 'equal' ? equalShare.value : due.value));
const remainingAfter = computed(() => {
    if (mode.value === 'equal') {
        return Math.max(0, remainingBill.value - due.value);
    }

    return Math.max(0, full.value - due.value);
});

const cardCents = computed(() => (method.value === 'mixed' ? Math.min(due.value, parseMoney(cardAmount.value)) : method.value === 'card' ? due.value : 0));
const cashCents = computed(() => due.value - cardCents.value);
const tenderedCents = computed(() => (tendered.value ? parseMoney(tendered.value) : cashCents.value));
const change = computed(() => Math.max(0, tenderedCents.value - cashCents.value));
const insufficient = computed(() => cashCents.value > 0 && tenderedCents.value < cashCents.value);
const tipCents = computed(() => {
    if (method.value !== 'card' || customCents.value === null) {
        return 0;
    }

    return Math.max(0, customCents.value - due.value);
});
const surplus = computed(() => (tipCents.value > 0 ? tipCents.value : change.value));
const quickAmounts = computed(() => {
    const amounts = new Set<number>();

    for (const note of [500, 1000, 2000, 5000, 10000]) {
        if (note >= cashCents.value) {
            amounts.add(note);
        }
    }

    amounts.add(Math.ceil(cashCents.value / 100) * 100);

    return [...amounts].filter((a) => a > cashCents.value).sort((a, b) => a - b).slice(0, 4);
});

function lineName(uuid: string): string {
    const line = order.value?.lines.find((l) => l.uuid === uuid);

    return line ? tr(line.name) : '';
}

function lineDetails(uuid: string): string[] {
    const line = order.value?.lines.find((l) => l.uuid === uuid);

    if (!order.value || !line) {
        return [];
    }

    return [...line.modifiers.map((m) => `+ ${tr(m.name)}`), ...childLines(order.value, line).filter((c) => !c.voided).map((c) => `· ${tr(c.name)}`)];
}

function unitAmount(uuid: string): number {
    const line = order.value?.lines.find((l) => l.uuid === uuid);

    return order.value && line ? lineAmount(line, order.value.lines, 1) : 0;
}

function adjust(uuid: string, available: number, delta: number): void {
    chosen[uuid] = Math.min(available, Math.max(0, (chosen[uuid] ?? 0) + delta));
    customCents.value = null;
}

function toggleLine(uuid: string, available: number): void {
    chosen[uuid] = (chosen[uuid] ?? 0) === available ? 0 : available;
    customCents.value = null;
}

function setMode(value: Mode): void {
    if (split.value && value !== 'equal') {
        toast.error(t('cashier.splitInProgress'));

        return;
    }

    mode.value = value;
    customCents.value = null;
}

function setParts(delta: number): void {
    if (split.value) {
        return;
    }

    parts.value = Math.min(20, Math.max(2, parts.value + delta));
    customCents.value = null;
}

function openPaidMore(): void {
    keypadValue.value = customCents.value ? formatMoney(customCents.value, false) : '';
    keypadOpen.value = true;
}

function confirmPaidMore(cents: number): void {
    customCents.value = cents;
    keypadOpen.value = false;

    if (method.value === 'cash' || method.value === 'mixed') {
        tendered.value = formatMoney(cents, false);
    }
}

function clearPaidMore(): void {
    customCents.value = null;
}

async function charge(): Promise<void> {
    if (!order.value || due.value <= 0 || insufficient.value || charging.value) {
        return;
    }

    if (!state.cashier?.session) {
        toast.error(t('cashier.sessionClosed'));

        return;
    }

    const payments: PaymentInput[] = [];
    const cardPay = cardCents.value + tipCents.value;
    const cashTendered = customCents.value !== null && (method.value === 'cash' || method.value === 'mixed')
        ? Math.max(tenderedCents.value, customCents.value)
        : tenderedCents.value;

    if (cardPay > 0) {
        payments.push({ method: 'card', amount: cardPay });
    }

    if (cashCents.value > 0) {
        payments.push({ method: 'cash', amount: cashCents.value, tendered: cashTendered });
    }

    charging.value = true;

    try {
        const current = order.value;
        const isEqual = mode.value === 'equal';
        const coversRest = isEqual && remainingAfter.value <= 0;
        const finalPart = isEqual && (partsLeft.value === 1 || coversRest);
        const issued = await issueTicket(current, selection.value, payments, {
            factor: factor.value,
            partLabel: isEqual ? t('cashier.partOf', { n: paidParts.value + 1, total: parts.value }) : null,
            markPaid: !isEqual || finalPart,
            closeOrder: mode.value === 'all' || finalPart,
            tip: tipCents.value,
        });

        if (isEqual) {
            if (finalPart) {
                delete splits[current.uuid];
            } else {
                splits[current.uuid] = { parts: parts.value, paidParts: paidParts.value + 1, paidAmount: (split.value?.paidAmount ?? 0) + due.value };
            }
        }

        for (const key of Object.keys(chosen)) {
            delete chosen[key];
        }

        tendered.value = '';
        cardAmount.value = '';
        customCents.value = null;
        result.value = { ...issued, surplus: issued.change + issued.tip, closed: !isActive(followOrder(current.uuid)) };
        toast.success(t('cashier.ticketIssued', { number: issued.fullNumber }));
    } catch (error) {
        toast.error(error instanceof Error && error.message === 'cashier_not_ready' ? t('cashier.notCashierDevice') : t('common.error'));
    } finally {
        charging.value = false;
    }
}

async function printTicket(): Promise<void> {
    if (!result.value || printing.value) {
        return;
    }

    printing.value = true;

    try {
        await openTicketPdf(result.value.fullNumber, result.value.document);
    } catch {
        toast.error(t('common.error'));
    } finally {
        printing.value = false;
    }
}

function finish(): void {
    const closed = result.value?.closed;
    result.value = null;

    if (closed || !order.value) {
        go('/caixa');
    }
}
</script>

<template>
    <div v-if="!order && !result" class="flex h-full flex-col items-center justify-center gap-4 p-6 text-center">
        <p class="text-slate-500">{{ t('cashier.orderClosed') }}</p>
        <Button class="bg-[#00056a]" @click="go('/caixa')"><ArrowLeft class="size-4" /> {{ t('cashier.title') }}</Button>
    </div>

    <div v-else-if="order" class="grid h-full min-h-0 lg:grid-cols-[1fr_420px]">
        <section class="flex min-h-0 flex-col border-r bg-white dark:bg-slate-900">
            <header class="flex items-center gap-2 border-b p-3">
                <Button variant="ghost" size="icon" class="size-11" @click="go('/caixa')"><ArrowLeft class="size-5" /></Button>
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-lg font-bold">{{ orderLabel(order) }}</h2>
                    <p class="text-xs text-slate-500">{{ waiterName(order) }}<template v-if="order.guests"> · {{ t('floor.guests', { count: order.guests }) }}</template></p>
                </div>
                <Button variant="outline" class="h-11" @click="go(order.tableId ? `/taula/${order.tableId}` : `/comanda/${order.uuid}`)">{{ t('order.title') }}</Button>
            </header>

            <div class="flex gap-1 border-b p-2">
                <button
                    v-for="m in MODES"
                    :key="m"
                    type="button"
                    class="h-11 flex-1 rounded-lg text-sm font-medium"
                    :class="mode === m ? 'bg-[#00056a] text-white' : 'bg-slate-100 dark:bg-slate-800'"
                    @click="setMode(m)"
                >
                    {{ m === 'all' ? t('cashier.payAll') : m === 'items' ? t('cashier.splitByItems') : t('cashier.splitEqual') }}
                </button>
            </div>

            <div v-if="mode === 'equal'" class="flex items-center justify-between gap-3 border-b bg-slate-50 p-3 dark:bg-slate-800">
                <span class="font-medium">{{ t('cashier.parts') }}</span>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="icon" class="size-10" :disabled="!!split" @click="setParts(-1)"><Minus class="size-4" /></Button>
                    <span class="w-8 text-center text-xl font-bold">{{ parts }}</span>
                    <Button variant="outline" size="icon" class="size-10" :disabled="!!split" @click="setParts(1)"><Plus class="size-4" /></Button>
                </div>
                <span class="text-sm font-semibold">{{ t('cashier.partOf', { n: paidParts + 1, total: parts }) }}</span>
            </div>

            <p v-if="mode === 'items'" class="px-4 pt-3 text-sm text-slate-500">{{ t('cashier.selectItems') }}</p>

            <ul class="min-h-0 flex-1 divide-y overflow-y-auto">
                <li v-for="{ line, quantity } in pending" :key="line.uuid" class="flex items-center gap-3 px-4 py-3">
                    <template v-if="mode === 'items'">
                        <div class="flex items-center gap-1">
                            <Button variant="outline" size="icon" class="size-10" @click="adjust(line.uuid, quantity, -1)"><Minus class="size-4" /></Button>
                            <span class="w-12 text-center text-lg font-bold tabular-nums">{{ chosen[line.uuid] ?? 0 }}/{{ quantity }}</span>
                            <Button variant="outline" size="icon" class="size-10" @click="adjust(line.uuid, quantity, 1)"><Plus class="size-4" /></Button>
                        </div>
                    </template>
                    <span v-else class="w-8 text-center text-lg font-bold tabular-nums">{{ quantity }}</span>
                    <button type="button" class="min-w-0 flex-1 text-left" @click="mode === 'items' && toggleLine(line.uuid, quantity)">
                        <span class="block truncate font-medium">{{ lineName(line.uuid) }}</span>
                        <span v-for="detail in lineDetails(line.uuid)" :key="detail" class="block truncate text-xs text-slate-500">{{ detail }}</span>
                    </button>
                    <span class="font-semibold tabular-nums">{{ formatMoney(mode === 'items' ? unitAmount(line.uuid) * (chosen[line.uuid] ?? 0) : unitAmount(line.uuid) * quantity) }}</span>
                </li>
            </ul>

            <footer class="space-y-1 border-t p-4 text-sm">
                <div v-if="surchargeRate" class="flex justify-between text-slate-500">
                    <span>{{ t('cashier.surcharge', { percent: surchargeRate }) }}</span>
                    <span>{{ t('cashier.included') }}</span>
                </div>
                <div class="flex justify-between text-base font-semibold">
                    <span>{{ t('common.total') }}</span>
                    <span class="tabular-nums">{{ formatMoney(full) }}</span>
                </div>
                <div v-if="split" class="flex justify-between text-emerald-700">
                    <span>{{ t('cashier.alreadyPaid') }}</span>
                    <span class="tabular-nums">{{ formatMoney(split.paidAmount) }}</span>
                </div>
            </footer>
        </section>

        <section class="flex min-h-0 flex-col gap-4 overflow-y-auto bg-slate-50 p-4 dark:bg-slate-950">
            <div class="rounded-2xl bg-[#00056a] p-5 text-white">
                <p class="text-sm opacity-80">{{ t('cashier.charge') }}</p>
                <p class="text-4xl font-bold tabular-nums">{{ formatMoney(customCents && method === 'card' ? customCents : due) }}</p>
                <p v-if="remainingAfter" class="mt-1 text-sm opacity-80">{{ t('cashier.remaining') }}: {{ formatMoney(remainingAfter) }}</p>
                <p v-if="customCents" class="mt-1 text-sm opacity-80">{{ mode === 'equal' ? t('cashier.partShare') : t('cashier.dueAmount') }}: {{ formatMoney(baseDue) }}</p>
            </div>

            <div v-if="due > 0" class="space-y-2">
                <Button variant="outline" class="h-14 w-full text-base" @click="openPaidMore">{{ t('cashier.paidMore') }}</Button>
                <button v-if="customCents" type="button" class="w-full text-sm text-slate-500 underline" @click="clearPaidMore">{{ t('cashier.paidMoreClear') }}</button>
            </div>

            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="m in METHODS"
                    :key="m.id"
                    type="button"
                    class="flex h-16 flex-col items-center justify-center gap-1 rounded-xl border-2 bg-white text-sm font-medium dark:bg-slate-900"
                    :class="method === m.id ? 'border-[#00056a] text-[#00056a] dark:border-blue-400 dark:text-blue-300' : 'border-transparent'"
                    @click="method = m.id"
                >
                    <component :is="m.icon" class="size-6" />
                    {{ t(`cashier.${m.id}`) }}
                </button>
            </div>

            <div v-if="method === 'mixed'" class="space-y-1">
                <label class="text-sm font-medium">{{ t('cashier.cardAmount') }}</label>
                <Input v-model="cardAmount" inputmode="decimal" class="h-12 text-lg" placeholder="0,00" />
                <p class="text-sm text-slate-500">{{ t('cashier.cashAmount') }}: {{ formatMoney(cashCents) }}</p>
            </div>

            <div v-if="cashCents > 0" class="space-y-2">
                <label class="text-sm font-medium">{{ t('cashier.tendered') }}</label>
                <Input v-model="tendered" inputmode="decimal" class="h-12 text-lg" :placeholder="formatMoney(cashCents, false)" @keyup.enter="charge" />
                <div class="grid grid-cols-4 gap-2">
                    <Button variant="outline" class="h-11" @click="tendered = ''">{{ t('cashier.exact') }}</Button>
                    <Button v-for="amount in quickAmounts" :key="amount" variant="outline" class="h-11" @click="tendered = formatMoney(amount, false)">{{ formatMoney(amount) }}</Button>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-white p-3 text-lg dark:bg-slate-900" :class="insufficient ? 'text-red-600' : ''">
                    <span>{{ insufficient ? t('cashier.insufficient') : t('cashier.change') }}</span>
                    <span class="font-bold tabular-nums">{{ formatMoney(change) }}</span>
                </div>
            </div>

            <Button class="mt-auto h-16 bg-emerald-600 text-xl hover:bg-emerald-700" :disabled="due <= 0 || insufficient || charging" @click="charge">
                <Wallet class="size-6" /> {{ charging ? t('cashier.charging') : `${t('cashier.charge')} ${formatMoney(customCents && method === 'card' ? customCents : due)}` }}
            </Button>
            <p v-if="surplus > 0" class="rounded-xl bg-amber-100 px-4 py-3 text-center text-lg font-semibold text-amber-950 dark:bg-amber-900/40 dark:text-amber-100">
                {{ t('cashier.surplus', { amount: formatMoney(surplus) }) }}
            </p>
        </section>

        <Teleport to="body">
            <MoneyKeypad
                v-if="keypadOpen"
                v-model="keypadValue"
                :title="t('cashier.paidMore')"
                :min-cents="baseDue + 1"
                @confirm="confirmPaidMore"
                @cancel="keypadOpen = false"
            />
        </Teleport>
    </div>

    <Dialog :open="!!result" @update:open="(v) => !v && finish()">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><CheckCircle2 class="size-6 text-emerald-600" /> {{ t('cashier.ticketIssued', { number: result?.fullNumber ?? '' }) }}</DialogTitle>
            </DialogHeader>
            <div v-if="result && result.change > 0" class="rounded-xl bg-amber-100 p-4 text-center text-amber-900">
                <p class="text-sm">{{ t('cashier.change') }}</p>
                <p class="text-4xl font-bold tabular-nums">{{ formatMoney(result.change) }}</p>
            </div>
            <div class="flex justify-center"><PrintPreview v-if="result" :document="result.document" /></div>
            <p v-if="result && result.surplus > 0" class="rounded-xl bg-amber-100 px-4 py-3 text-center text-lg font-semibold text-amber-950">
                {{ t('cashier.surplus', { amount: formatMoney(result.surplus) }) }}
            </p>
            <p class="text-center text-sm text-slate-600">{{ t('cashier.printTicketQuestion') }}</p>
            <p class="text-center text-xs text-slate-500">{{ t('cashier.openPdfHint') }}</p>
            <DialogFooter>
                <Button variant="outline" class="h-12 w-full sm:w-auto" @click="finish">{{ result?.closed ? t('common.done') : t('cashier.nextPart') }}</Button>
                <Button class="h-12 w-full bg-[#00056a] sm:flex-1" :disabled="printing" @click="printTicket">
                    <FileText class="size-5" /> {{ printing ? t('cashier.openingPdf') : t('cashier.openPdf') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
