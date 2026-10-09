<script setup lang="ts">
import { ArrowLeft, ArrowRightLeft, BellRing, Ban, ChefHat, Combine, FileText, Minus, MoreHorizontal, Percent, Plus, Receipt, Send, Timer, Trash2, Undo2, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import LineActions from '@/components/pos/LineActions.vue';
import ModifierDialog from '@/components/pos/ModifierDialog.vue';
import ProductPicker from '@/components/pos/ProductPicker.vue';
import SetMenuWizard from '@/components/pos/SetMenuWizard.vue';
import TablePicker from '@/components/pos/TablePicker.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { t, tr } from '@/i18n';
import { productModifierGroups } from '@/pos/catalog';
import { formatMoney } from '@/pos/money';
import {
    addToDraft,
    childLines,
    discardDraft,
    draftFor,
    draftFromCustom,
    draftFromProduct,
    draftLineTotal,
    marchCourse,
    billViewFor,
    orderTotal,
    proformaDocument,
    proformaTitle,
    requestBill,
    sendDraft,
    topLines,
} from '@/pos/orders';
import type { Draft, DraftLine, InventedProduct } from '@/pos/orders';
import { lineAmount } from '@/pos/print';
import { go, route } from '@/pos/router';
import { activeOrderForTable, followOrder, isActive, state } from '@/pos/store';
import { openTicketPdf } from '@/pos/ticketPdf';
import { enqueue } from '@/pos/sync';
import type { DiningTable, OrderLine, Product } from '@/pos/types';

const tableId = computed(() => (route.value.name === 'taula' ? Number(route.value.params[0]) : null));
const routeUuid = computed(() => (route.value.name === 'comanda' && route.value.params[0] !== 'nova' ? route.value.params[0] : null));

const order = computed(() => {
    if (tableId.value) {
        return activeOrderForTable(tableId.value);
    }

    const found = followOrder(routeUuid.value);

    return found && isActive(found) ? found : null;
});

const table = computed(() => (tableId.value ? state.tables[tableId.value] : order.value?.tableId ? state.tables[order.value.tableId] : null));
const zone = computed(() => (table.value ? state.zones[table.value.zoneId] : null));

const draft = ref<Draft>(draftFor(order.value, tableId.value, routeUuid.value ? null : null));

watch(
    () => order.value?.uuid,
    () => {
        if (!tableId.value && order.value && draft.value.orderUuid !== order.value.uuid && !draft.value.lines.length) {
            draft.value = draftFor(order.value, null);
        }
    },
);

const course = ref<number | null>(null);
const editing = ref<{ product: Product | null; line: DraftLine | null } | null>(null);
const menuWizard = ref(false);
const lineAction = ref<OrderLine | null | undefined>(undefined);
const picker = ref<'move' | 'merge' | null>(null);
const sending = ref(false);
const printingProforma = ref(false);
const showPicker = ref(true);

const sentLines = computed(() => topLines(order.value));
const pendingTotal = computed(() => orderTotal(order.value));
const draftTotal = computed(() => draft.value.lines.reduce((sum, line) => sum + draftLineTotal(line), 0));
const surchargeRate = computed(() => (zone.value?.appliesTerraceSurcharge ? Number(state.settings?.terrace_surcharge_percent ?? 0) : 0));
const heldCourses = computed(() => {
    const courses = new Set<number>();

    for (const ticket of Object.values(state.kitchenTickets)) {
        if (order.value && ticket.orderUuid === order.value.uuid && ticket.held && ticket.course) {
            courses.add(ticket.course);
        }
    }

    return [...courses].sort();
});
const hasSecondCourse = computed(() => draft.value.lines.some((l) => (l.course ?? 0) >= 2 || l.children.some((c) => (c.course ?? 0) >= 2)));
const title = computed(() => (table.value ? t('order.table', { label: table.value.label }) : order.value?.label || draft.value.label || t('floor.quickSaleLabel')));
const canCharge = computed(() => state.device?.type === 'cashier' && !!order.value);

function lineStatus(line: OrderLine): 'pending' | 'preparing' | 'ready' | 'served' | 'held' | null {
    const ticket = Object.values(state.kitchenTickets).find((k) => k.items.some((i) => i.lineUuid === line.uuid));

    if (!ticket) {
        return null;
    }

    return ticket.held ? 'held' : ticket.status;
}

function pickProduct(product: Product): void {
    if (productModifierGroups(product).some((g) => g.required)) {
        editing.value = { product, line: null };

        return;
    }

    addToDraft(draft.value, draftFromProduct(product, [], '', course.value));
}

function inventProduct(product: InventedProduct): void {
    addToDraft(draft.value, draftFromCustom(product.name, product.unitPrice, product.destinationId, course.value));
}

function editDraftLine(line: DraftLine): void {
    const product = line.productId ? (state.products[line.productId] ?? null) : null;
    editing.value = { product, line };
}

function saveModifiers(data: { modifiers: DraftLine['modifiers']; note: string; course: number | null; quantity: number }): void {
    const target = editing.value;

    if (!target) {
        return;
    }

    if (target.line) {
        Object.assign(target.line, data);
    } else if (target.product) {
        addToDraft(draft.value, draftFromProduct(target.product, data.modifiers, data.note, data.course, data.quantity));
    }
}

function removeDraftLine(line: DraftLine): void {
    draft.value.lines = draft.value.lines.filter((l) => l.key !== line.key);
}

function changeQty(line: DraftLine, delta: number): void {
    line.quantity += delta;

    if (line.quantity <= 0) {
        removeDraftLine(line);
    }
}

async function send(holdSecond = false): Promise<void> {
    if (!draft.value.lines.length || sending.value) {
        return;
    }

    sending.value = true;

    try {
        const before = order.value?.uuid;
        const uuid = await sendDraft(draft.value, holdSecond);
        toast.success(t('order.sentOk'));

        if (before && before !== uuid) {
            toast.info(t('order.mergedInfo'));
        }

        draft.value = draftFor(followOrder(uuid), tableId.value);

        if (!tableId.value) {
            go(`/comanda/${uuid}`);
        } else {
            go('/sala');
        }
    } finally {
        sending.value = false;
    }
}

async function march(c: number): Promise<void> {
    if (order.value) {
        await marchCourse(order.value, c);
        toast.success(t('order.marched'));
    }
}

async function bill(): Promise<void> {
    if (!order.value) {
        return;
    }

    const view = billViewFor(order.value);
    const isCashier = state.device?.type === 'cashier';
    await requestBill(order.value, view, { print: !isCashier });

    if (isCashier) {
        await printProforma();
    }

    toast.success(t('order.billRequested'));
}

async function printProforma(): Promise<void> {
    if (!order.value || printingProforma.value) {
        return;
    }

    printingProforma.value = true;

    try {
        await openTicketPdf(proformaTitle(order.value), proformaDocument(order.value));
    } catch {
        toast.error(t('common.error'));
    } finally {
        printingProforma.value = false;
    }
}

async function reopen(): Promise<void> {
    if (order.value) {
        await enqueue('order.reopen', { orderUuid: order.value.uuid });
    }
}

async function setGuests(): Promise<void> {
    const value = prompt(t('order.guestsTitle'), String(order.value?.guests ?? draft.value.guests ?? ''));
    const guests = value ? Number.parseInt(value, 10) : NaN;

    if (!Number.isFinite(guests) || guests <= 0) {
        return;
    }

    if (order.value) {
        await enqueue('order.update', { orderUuid: order.value.uuid, guests });
    } else {
        draft.value.guests = guests;
    }
}

async function pickTable(target: DiningTable): Promise<void> {
    if (!order.value) {
        return;
    }

    if (picker.value === 'move') {
        await enqueue('order.move', { orderUuid: order.value.uuid, toTableId: target.id });
        go(`/taula/${target.id}`);
    } else if (picker.value === 'merge') {
        const into = activeOrderForTable(target.id);

        if (into) {
            await enqueue('order.merge', { orderUuid: order.value.uuid, intoOrderUuid: into.uuid });
            go(`/taula/${target.id}`);
        }
    }
}

async function cancelEmpty(): Promise<void> {
    if (order.value && confirm(t('common.areYouSure'))) {
        await enqueue('order.cancel', { orderUuid: order.value.uuid, reason: 'empty' });
        go('/sala');
    }
}

function leave(): void {
    if (draft.value.lines.length && !confirm(t('order.unsentWarning'))) {
        return;
    }

    go(state.device?.type === 'cashier' && !tableId.value ? '/caixa' : '/sala');
}

function discard(): void {
    if (confirm(t('common.areYouSure'))) {
        draft.value.lines = [];
        discardDraft(draft.value);
        draft.value = draftFor(order.value, tableId.value);
    }
}

const statusIcon: Record<string, { cls: string; label: string }> = {
    pending: { cls: 'bg-slate-200 text-slate-700', label: 'kds.pending' },
    preparing: { cls: 'bg-sky-100 text-sky-800', label: 'kds.preparing' },
    ready: { cls: 'bg-emerald-500 text-white', label: 'kds.ready' },
    served: { cls: 'bg-slate-100 text-slate-400', label: 'kds.served' },
    held: { cls: 'bg-amber-100 text-amber-800', label: 'order.held' },
};
</script>

<template>
    <div class="flex h-full min-h-0 flex-col lg:flex-row">
        <section class="flex min-h-0 flex-col border-r bg-white lg:w-[26rem] xl:w-[30rem]" :class="showPicker ? 'max-lg:h-[45%]' : 'flex-1'">
            <header class="flex shrink-0 items-center gap-2 border-b px-2 py-2">
                <Button variant="ghost" size="icon" class="size-11" @click="leave"><ArrowLeft class="size-5" /></Button>
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-lg font-bold text-[#00056a]">{{ title }}</h2>
                    <p class="flex items-center gap-2 text-xs text-slate-500">
                        <span v-if="zone">{{ tr(zone.name) }}</span>
                        <span v-if="order?.status === 'bill_requested'" class="rounded bg-amber-100 px-1.5 font-semibold text-amber-800">{{ t('order.billRequested') }}</span>
                        <span v-if="surchargeRate">{{ t('order.surchargeInfo', { percent: surchargeRate }) }}</span>
                    </p>
                </div>
                <Button variant="outline" class="h-11 gap-1" @click="setGuests">
                    <Users class="size-4" />
                    {{ order?.guests ?? draft.guests ?? '–' }}
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="icon" class="size-11"><MoreHorizontal class="size-5" /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem :disabled="!order" @select="picker = 'move'"><ArrowRightLeft class="size-4" /> {{ t('order.move') }}</DropdownMenuItem>
                        <DropdownMenuItem :disabled="!order" @select="picker = 'merge'"><Combine class="size-4" /> {{ t('order.merge') }}</DropdownMenuItem>
                        <DropdownMenuItem :disabled="!order" @select="lineAction = null"><Percent class="size-4" /> {{ t('order.discountOrder') }}</DropdownMenuItem>
                        <DropdownMenuItem v-if="order?.status === 'bill_requested'" @select="reopen"><Undo2 class="size-4" /> {{ t('order.reopen') }}</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem v-if="draft.lines.length" class="text-red-700" @select="discard"><Trash2 class="size-4" /> {{ t('order.discardDraft') }}</DropdownMenuItem>
                        <DropdownMenuItem v-if="order && !pendingTotal" class="text-red-700" @select="cancelEmpty"><Ban class="size-4" /> {{ t('order.cancelOrder') }}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </header>

            <div class="flex shrink-0 gap-1 border-b px-2 py-1.5">
                <span class="self-center pr-1 text-xs font-semibold text-slate-500">{{ t('order.course') }}</span>
                <button
                    v-for="c in [null, 1, 2, 3]"
                    :key="String(c)"
                    type="button"
                    class="h-9 flex-1 rounded-lg text-sm font-semibold"
                    :class="course === c ? 'bg-[#00056a] text-white' : 'bg-slate-100 text-slate-600'"
                    @click="course = c"
                >
                    {{ c ? t(`order.courses.${c}`) : '–' }}
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <p v-if="!sentLines.length && !draft.lines.length" class="p-6 text-center text-sm text-slate-500">{{ t('order.empty') }}</p>

                <ul v-if="sentLines.length" class="divide-y">
                    <li
                        v-for="line in sentLines"
                        :key="line.uuid"
                        class="flex cursor-pointer gap-2 px-3 py-2 hover:bg-slate-50"
                        :class="line.voided ? 'opacity-50' : ''"
                        @click="!line.voided && (lineAction = line)"
                    >
                        <span class="w-7 shrink-0 text-right font-bold">{{ line.quantity }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium" :class="line.voided ? 'line-through' : ''">
                                {{ tr(line.name) }}
                                <span v-if="line.course" class="ml-1 rounded bg-slate-100 px-1 text-[10px] font-semibold text-slate-600">{{ t(`order.courses.${line.course}`) }}</span>
                            </p>
                            <p v-for="m in line.modifiers" :key="String(m.id) + tr(m.name)" class="text-xs text-slate-500">+ {{ tr(m.name) }}</p>
                            <p v-for="child in childLines(order!, line)" :key="child.uuid" class="text-xs text-slate-600" :class="child.voided ? 'line-through' : ''">
                                · {{ tr(child.name) }}
                                <span v-if="lineStatus(child)" class="ml-1 rounded px-1 text-[10px]" :class="statusIcon[lineStatus(child)!].cls">{{ t(statusIcon[lineStatus(child)!].label) }}</span>
                            </p>
                            <p v-if="line.note" class="text-xs font-medium text-amber-700">! {{ line.note }}</p>
                            <p v-if="line.discountType" class="text-xs text-emerald-700">
                                {{ t('order.discount') }} {{ line.discountType === 'percent' ? `${line.discountValue}%` : formatMoney(line.discountValue) }}
                                <span v-if="line.discountReason">· {{ line.discountReason }}</span>
                            </p>
                            <p v-if="line.voided" class="text-xs text-red-600">{{ t('order.voided') }}<span v-if="line.voidReason"> · {{ line.voidReason }}</span></p>
                            <p v-if="line.paidQuantity" class="text-xs text-sky-700">{{ t('order.paid') }}: {{ line.paidQuantity }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span class="font-semibold">{{ formatMoney(lineAmount(line, order!.lines)) }}</span>
                            <span v-if="lineStatus(line)" class="rounded px-1.5 text-[10px] font-semibold" :class="statusIcon[lineStatus(line)!].cls">{{ t(statusIcon[lineStatus(line)!].label) }}</span>
                        </div>
                    </li>
                </ul>

                <div v-if="draft.lines.length" class="border-t-4 border-amber-300 bg-amber-50/60">
                    <p class="px-3 pt-2 text-xs font-bold tracking-wide text-amber-800 uppercase">{{ t('order.draft') }}</p>
                    <ul class="divide-y divide-amber-200/60">
                        <li v-for="line in draft.lines" :key="line.key" class="flex items-center gap-2 px-2 py-2">
                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" class="flex size-9 items-center justify-center rounded-lg bg-white shadow-sm" @click="changeQty(line, -1)"><Minus class="size-4" /></button>
                                <span class="w-6 text-center font-bold">{{ line.quantity }}</span>
                                <button type="button" class="flex size-9 items-center justify-center rounded-lg bg-white shadow-sm" @click="changeQty(line, 1)"><Plus class="size-4" /></button>
                            </div>
                            <button type="button" class="min-w-0 flex-1 text-left" @click="editDraftLine(line)">
                                <p class="font-medium">
                                    {{ tr(line.name) }}
                                    <span v-if="line.course" class="ml-1 rounded bg-white px-1 text-[10px] font-semibold text-slate-600">{{ t(`order.courses.${line.course}`) }}</span>
                                </p>
                                <p v-for="m in line.modifiers" :key="String(m.id)" class="text-xs text-slate-500">+ {{ tr(m.name) }}</p>
                                <p v-for="c in line.children" :key="c.key" class="text-xs text-slate-600">· {{ tr(c.name) }}</p>
                                <p v-if="line.note" class="text-xs font-medium text-amber-700">! {{ line.note }}</p>
                            </button>
                            <span class="shrink-0 font-semibold">{{ formatMoney(draftLineTotal(line)) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <footer class="shrink-0 border-t bg-slate-50 p-2">
                <div class="mb-2 flex items-baseline justify-between px-1">
                    <span class="text-sm text-slate-500">{{ t('common.total') }}</span>
                    <span class="text-2xl font-bold text-[#00056a]">{{ formatMoney(pendingTotal + draftTotal) }}</span>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <Button v-if="draft.lines.length" class="col-span-2 h-14 bg-emerald-600 text-lg hover:bg-emerald-700" :disabled="sending" @click="send(false)">
                        <Send class="size-5" />
                        {{ sending ? t('order.sending') : t('order.send') }} ({{ draft.lines.reduce((s, l) => s + l.quantity, 0) }})
                    </Button>
                    <Button v-if="draft.lines.length && hasSecondCourse" variant="outline" class="col-span-2 h-11" :disabled="sending" @click="send(true)">
                        <Timer class="size-4" />
                        {{ t('order.sendHold') }}
                    </Button>
                    <Button v-for="c in heldCourses" :key="c" class="h-12 bg-amber-500 text-amber-950 hover:bg-amber-400" @click="march(c)">
                        <ChefHat class="size-5" />
                        {{ t('order.march', { course: t(`order.courses.${c}`) }) }}
                    </Button>
                    <Button v-if="order && !draft.lines.length" variant="outline" class="h-12" @click="bill">
                        <BellRing class="size-5" />
                        {{ t('order.requestBill') }}
                    </Button>
                    <Button v-if="canCharge && !draft.lines.length" variant="outline" class="h-12" :disabled="printingProforma" @click="printProforma">
                        <FileText class="size-5" />
                        {{ printingProforma ? t('cashier.openingPdf') : t('order.printProforma') }}
                    </Button>
                    <Button v-if="canCharge && !draft.lines.length" class="h-12 bg-[#00056a]" @click="go(`/caixa/${order!.uuid}`)">
                        <Receipt class="size-5" />
                        {{ t('order.charge') }}
                    </Button>
                </div>
                <button type="button" class="mt-2 w-full text-center text-xs text-slate-500 lg:hidden" @click="showPicker = !showPicker">
                    {{ showPicker ? '▼' : '▲' }}
                </button>
            </footer>
        </section>

        <section v-show="showPicker" class="min-h-0 flex-1 bg-slate-100">
            <ProductPicker @pick="pickProduct" @menu="menuWizard = true" @invent="inventProduct" />
        </section>

        <ModifierDialog
            v-if="editing"
            :product="editing.product"
            :line="editing.line"
            :default-course="course"
            @save="saveModifiers"
            @remove="editing.line && removeDraftLine(editing.line)"
            @close="editing = null"
        />
        <SetMenuWizard v-if="menuWizard" @add="(line) => addToDraft(draft, line)" @close="menuWizard = false" />
        <LineActions v-if="lineAction !== undefined && order" :order="order" :line="lineAction" @close="lineAction = undefined" />
        <TablePicker
            v-if="picker"
            :title="picker === 'move' ? t('floor.moveTo') : t('floor.mergeWith')"
            :only-occupied="picker === 'merge'"
            :exclude="order?.tableId"
            @pick="pickTable"
            @close="picker = null"
        />
    </div>
</template>
