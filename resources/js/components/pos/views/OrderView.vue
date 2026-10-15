<script setup lang="ts">
import { ArrowLeft, ArrowRightLeft, BellRing, Ban, ChefHat, Combine, FileText, Minus, MoreHorizontal, Percent, Plus, Receipt, Send, Settings, Timer, Trash2, Undo2, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import LineActions from '@/components/pos/LineActions.vue';
import LinePriceDialog from '@/components/pos/LinePriceDialog.vue';
import ModifierDialog from '@/components/pos/ModifierDialog.vue';
import ProductPicker from '@/components/pos/ProductPicker.vue';
import ProductWizard from '@/components/pos/ProductWizard.vue';
import SetMenuWizard from '@/components/pos/SetMenuWizard.vue';
import TableMoveDialog from '@/components/pos/TableMoveDialog.vue';
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
    editDraftLine as applyDraftEdit,
    editSentLines,
    groupOrderLines,
    marchCourse,
    billViewFor,
    moveDraft,
    orderTotal,
    proformaDocument,
    proformaTitle,
    requestBill,
    sendDraft,
} from '@/pos/orders';
import type { Draft, DraftLine, InventedProduct, LineEdit, LineGroup } from '@/pos/orders';
import { go, route } from '@/pos/router';
import { activeOrderForTable, followOrder, isActive, state } from '@/pos/store';
import { openTicketPdf } from '@/pos/ticketPdf';
import { enqueue } from '@/pos/sync';
import type { DiningTable, LineModifier, OrderLine, Product } from '@/pos/types';

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
const wizard = ref<Product | null>(null);
const lineAction = ref<LineGroup | null | undefined>(undefined);
const priceEdit = ref<{ draft: DraftLine } | { group: LineGroup } | null>(null);
const picker = ref<'merge' | null>(null);
const moving = ref(false);
const sending = ref(false);
const printingProforma = ref(false);
const showPicker = ref(true);

const sentGroups = computed(() => groupOrderLines(order.value));
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
const canSendAndCharge = computed(() => state.device?.type === 'cashier' && !tableId.value);

type LineStatus = 'pending' | 'preparing' | 'ready' | 'served' | 'held';
const STATUS_ORDER: LineStatus[] = ['held', 'pending', 'preparing', 'ready', 'served'];

function lineStatus(line: OrderLine): LineStatus | null {
    const ticket = Object.values(state.kitchenTickets).find((k) => k.items.some((i) => i.lineUuid === line.uuid));

    if (!ticket) {
        return null;
    }

    return ticket.held ? 'held' : ticket.status;
}

/** A grouped line shows the least advanced status of its rounds. */
function groupStatus(group: LineGroup): LineStatus | null {
    const statuses = group.lines.map(lineStatus).filter((s): s is LineStatus => s !== null);

    return statuses.length ? statuses.sort((a, b) => STATUS_ORDER.indexOf(a) - STATUS_ORDER.indexOf(b))[0] : null;
}

function pickProduct(product: Product): void {
    if (productModifierGroups(product).length) {
        wizard.value = product;

        return;
    }

    addToDraft(draft.value, draftFromProduct(product, [], '', course.value));
}

function addFromWizard(product: Product, data: { modifiers: LineModifier[]; note: string; course: number | null; quantity: number }): void {
    addToDraft(draft.value, draftFromProduct(product, data.modifiers, data.note, data.course, data.quantity));
}

function inventProduct(product: InventedProduct): void {
    addToDraft(draft.value, draftFromCustom(product.name, product.unitPrice, product.destinationId, course.value));
}

async function savePrice(edit: LineEdit): Promise<void> {
    const target = priceEdit.value;
    priceEdit.value = null;

    if (!target) {
        return;
    }

    if ('draft' in target) {
        await applyDraftEdit(target.draft, edit);
    } else {
        await editSentLines(target.group.lines.filter((l) => !l.voided), edit);
    }

    toast.success(t('order.lineEdited'));
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

async function send(holdSecond = false, charge = false): Promise<void> {
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

        if (charge) {
            go(`/caixa/${uuid}`);
        } else if (!tableId.value) {
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

    if (picker.value === 'merge') {
        const into = activeOrderForTable(target.id);

        if (into) {
            await enqueue('order.merge', { orderUuid: order.value.uuid, intoOrderUuid: into.uuid });
            go(`/taula/${target.id}`);
        }
    }
}

async function moveTo(target: DiningTable): Promise<void> {
    const from = tableId.value;

    if (!from || activeOrderForTable(target.id)) {
        toast.error(t('order.tableOccupied', { label: target.label }));

        return;
    }

    if (order.value) {
        await enqueue('order.move', { orderUuid: order.value.uuid, toTableId: target.id });
    }

    moveDraft(from, target.id);
    toast.success(t('order.moved', { label: target.label }));
    go(`/taula/${target.id}`);
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
                <Button v-if="tableId && (order || draft.lines.length)" variant="outline" class="h-11 gap-1 px-3" @click="moving = true">
                    <ArrowRightLeft class="size-4" />
                    <span class="max-sm:hidden">{{ t('order.moveTable') }}</span>
                </Button>
                <Button variant="outline" class="h-11 gap-1" @click="setGuests">
                    <Users class="size-4" />
                    {{ order?.guests ?? draft.guests ?? '–' }}
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="icon" class="size-11"><MoreHorizontal class="size-5" /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
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
                <p v-if="!sentGroups.length && !draft.lines.length" class="p-6 text-center text-sm text-slate-500">{{ t('order.empty') }}</p>

                <ul v-if="sentGroups.length" class="divide-y">
                    <li
                        v-for="group in sentGroups"
                        :key="group.key"
                        class="flex cursor-pointer items-center gap-2 px-3 py-2 hover:bg-slate-50"
                        :class="group.voided ? 'opacity-50' : ''"
                        @click="!group.voided && (lineAction = group)"
                    >
                        <span class="w-7 shrink-0 self-start text-right text-lg font-bold">{{ group.quantity }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium" :class="group.voided ? 'line-through' : ''">
                                {{ tr(group.first.name) }}
                                <span v-if="group.first.course" class="ml-1 rounded bg-slate-100 px-1 text-[10px] font-semibold text-slate-600">{{ t(`order.courses.${group.first.course}`) }}</span>
                            </p>
                            <p v-for="m in group.first.modifiers" :key="String(m.id) + tr(m.name)" class="text-xs text-slate-500">+ {{ tr(m.name) }}</p>
                            <p v-for="child in childLines(order!, group.first)" :key="child.uuid" class="text-xs text-slate-600" :class="child.voided ? 'line-through' : ''">
                                · {{ tr(child.name) }}
                                <span v-if="lineStatus(child)" class="ml-1 rounded px-1 text-[10px]" :class="statusIcon[lineStatus(child)!].cls">{{ t(statusIcon[lineStatus(child)!].label) }}</span>
                            </p>
                            <p v-if="group.first.note" class="text-xs font-medium text-amber-700">! {{ group.first.note }}</p>
                            <p v-if="group.first.discountType" class="text-xs text-emerald-700">
                                {{ t('order.discount') }} {{ group.first.discountType === 'percent' ? `${group.first.discountValue}%` : formatMoney(group.first.discountValue) }}
                                <span v-if="group.first.discountReason">· {{ group.first.discountReason }}</span>
                            </p>
                            <p v-if="group.voided" class="text-xs text-red-600">{{ t('order.voided') }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span v-if="group.lines.some((l) => l.unpriced) && !group.voided" class="rounded bg-amber-100 px-1.5 text-xs font-bold text-amber-800">{{ t('order.unpriced') }}</span>
                            <span v-else class="font-semibold">{{ formatMoney(group.amount) }}</span>
                            <span v-if="groupStatus(group)" class="rounded px-1.5 text-[10px] font-semibold" :class="statusIcon[groupStatus(group)!].cls">{{ t(statusIcon[groupStatus(group)!].label) }}</span>
                        </div>
                        <button
                            v-if="!group.voided"
                            type="button"
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600"
                            :aria-label="t('order.editLine')"
                            @click.stop="priceEdit = { group }"
                        >
                            <Settings class="size-5" />
                        </button>
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
                            <span v-if="line.unpriced" class="shrink-0 rounded bg-amber-100 px-1.5 text-xs font-bold text-amber-800">{{ t('order.unpriced') }}</span>
                            <span v-else class="shrink-0 font-semibold">{{ formatMoney(draftLineTotal(line)) }}</span>
                            <button type="button" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-slate-600 shadow-sm" :aria-label="t('order.editLine')" @click="priceEdit = { draft: line }">
                                <Settings class="size-4" />
                            </button>
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
                    <Button
                        v-if="draft.lines.length"
                        class="h-14 bg-emerald-600 text-lg hover:bg-emerald-700"
                        :class="canSendAndCharge ? '' : 'col-span-2'"
                        :disabled="sending"
                        @click="send(false)"
                    >
                        <Send class="size-5" />
                        {{ sending ? t('order.sending') : t('order.send') }} ({{ draft.lines.reduce((s, l) => s + l.quantity, 0) }})
                    </Button>
                    <Button v-if="draft.lines.length && canSendAndCharge" class="h-14 bg-[#00056a] text-lg" :disabled="sending" @click="send(false, true)">
                        <Receipt class="size-5" />
                        {{ t('order.sendAndCharge') }}
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
        <ProductWizard v-if="wizard" :product="wizard" :course="course" @add="(data) => addFromWizard(wizard!, data)" @close="wizard = null" />
        <SetMenuWizard v-if="menuWizard" @add="(line) => addToDraft(draft, line)" @close="menuWizard = false" />
        <LineActions v-if="lineAction !== undefined && order" :order="order" :group="lineAction" @edit="(group) => (priceEdit = { group })" @close="lineAction = undefined" />
        <LinePriceDialog
            v-if="priceEdit"
            :name="'draft' in priceEdit ? priceEdit.draft.name : priceEdit.group.first.name"
            :unit-price="'draft' in priceEdit ? priceEdit.draft.unitPrice : priceEdit.group.first.unitPrice"
            :unpriced="'draft' in priceEdit ? priceEdit.draft.unpriced : priceEdit.group.lines.some((l) => l.unpriced)"
            :product-id="'draft' in priceEdit ? priceEdit.draft.productId : priceEdit.group.first.productId"
            @save="savePrice"
            @close="priceEdit = null"
        />
        <TableMoveDialog v-if="moving && tableId" :from-table-id="tableId" @move="moveTo" @close="moving = false" />
        <TablePicker v-if="picker" :title="t('floor.mergeWith')" :only-occupied="true" :exclude="order?.tableId" @pick="pickTable" @close="picker = null" />
    </div>
</template>
