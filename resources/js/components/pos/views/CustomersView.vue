<script setup lang="ts">
import { ClipboardSignature, FileText, Pencil, Plus, Printer, Search, UserRound, Wallet, X } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CustomerForm from '@/components/pos/CustomerForm.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { HttpError } from '@/lib/http';
import { printOrOpen } from '@/pos/cashier';
import { attachTicket, collectNotes, createStatement, customerDetail, detachTicket, fetchStatement, noteDocument, searchCustomers, searchTickets, statementDocument } from '@/pos/customers';
import type { Customer, CustomerDetail, CustomerListItem, CustomerTicket, DeliveryNoteItem, Statement } from '@/pos/customers';
import { formatMoney } from '@/pos/money';
import { directTicketPrinter, ticketPrinterId, widthFor } from '@/pos/print';
import { go } from '@/pos/router';
import { upsert } from '@/pos/store';

/**
 * Regular customers (cashier, online): find or create them, see the tickets they still have
 * pending, add a paid ticket that was not assigned, and print the summary of everything,
 * whose QR asks for one invoice of all of it.
 */
const query = ref('');
const customers = ref<CustomerListItem[]>([]);
const detail = ref<CustomerDetail | null>(null);
const selected = ref<Set<string>>(new Set());
/** Delivery notes ticked to charge now. */
const selectedNotes = ref<Set<string>>(new Set());
const creating = ref(false);
const editing = ref(false);
const adding = ref(false);
const ticketQuery = ref('');
const ticketResults = ref<CustomerTicket[]>([]);
const busy = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;

const selectedNotesTotal = computed(() => (detail.value?.notes ?? []).filter((note) => selectedNotes.value.has(note.uuid)).reduce((sum, note) => sum + note.total, 0));
const selectedTotal = computed(() => (detail.value?.pending ?? []).filter((ticket) => selected.value.has(ticket.uuid)).reduce((sum, ticket) => sum + ticket.total, 0));

function failed(e: unknown): void {
    toast.error(e instanceof HttpError ? e.message : t('common.offlineOnly'));
}

async function search(): Promise<void> {
    try {
        customers.value = await searchCustomers(query.value.trim());
    } catch (e) {
        failed(e);
    }
}

watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(search, 250);
});

onMounted(search);

function show(value: CustomerDetail): void {
    detail.value = value;
    selected.value = new Set(value.pending.map((ticket) => ticket.uuid));
    selectedNotes.value = new Set(value.notes.map((note) => note.uuid));
}

async function open(customer: Pick<Customer, 'id'>): Promise<void> {
    creating.value = false;
    editing.value = false;

    try {
        show(await customerDetail(customer.id));
    } catch (e) {
        failed(e);
    }
}

async function saved(customer: Customer): Promise<void> {
    await search();
    await open(customer);
}

// Tickets to put in the summary by date: this month, last month or between two days.
const rangeFrom = ref('');
const rangeTo = ref('');

function day(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function selectRange(from: string, to: string): void {
    rangeFrom.value = from;
    rangeTo.value = to;
    selected.value = new Set(
        (detail.value?.pending ?? [])
            .filter((ticket) => {
                const issued = day(new Date(ticket.issuedAt));

                return (!from || issued >= from) && (!to || issued <= to);
            })
            .map((ticket) => ticket.uuid),
    );
}

function selectMonth(offset: number): void {
    const now = new Date();
    selectRange(day(new Date(now.getFullYear(), now.getMonth() + offset, 1)), day(new Date(now.getFullYear(), now.getMonth() + offset + 1, 0)));
}

function toggle(uuid: string, value: boolean): void {
    const next = new Set(selected.value);

    if (value) {
        next.add(uuid);
    } else {
        next.delete(uuid);
    }

    selected.value = next;
}

async function print(statement: Statement): Promise<void> {
    const outcome = await printOrOpen('statement', `${t('customers.statementTitle')} · ${statement.customer.code}`, statementDocument(statement, widthFor(ticketPrinterId())));

    if (outcome === 'printed') {
        toast.success(t('cashier.sentTo', { name: directTicketPrinter()?.name ?? '' }));
    }
}

async function printSummary(): Promise<void> {
    if (!detail.value || !selected.value.size || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const statement = await createStatement(detail.value.customer.id, [...selected.value]);
        await print(statement);
        show(await customerDetail(detail.value.customer.id));
        await search();
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}

function toggleNote(uuid: string, value: boolean): void {
    const next = new Set(selectedNotes.value);

    if (value) {
        next.add(uuid);
    } else {
        next.delete(uuid);
    }

    selectedNotes.value = next;
}

/** The ticked notes become an order of the customer and go to the usual payment screen. */
async function collect(): Promise<void> {
    if (!detail.value || !selectedNotes.value.size || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const order = await collectNotes(detail.value.customer.id, [...selectedNotes.value]);
        upsert('orders', order);
        go(`/caixa/${order.uuid}`);
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}

async function reprintNote(note: DeliveryNoteItem): Promise<void> {
    if (!detail.value) {
        return;
    }

    try {
        const outcome = await printOrOpen('delivery_note', `${t('deliveryNotes.title')} · ${detail.value.customer.code}`, noteDocument(note, detail.value.customer, widthFor(ticketPrinterId())));

        if (outcome === 'printed') {
            toast.success(t('cashier.sentTo', { name: directTicketPrinter()?.name ?? '' }));
        }
    } catch (e) {
        failed(e);
    }
}

async function reprint(id: number): Promise<void> {
    try {
        await print(await fetchStatement(id));
    } catch (e) {
        failed(e);
    }
}

async function findTickets(): Promise<void> {
    try {
        ticketResults.value = await searchTickets(ticketQuery.value.trim());
    } catch (e) {
        failed(e);
    }
}

watch(adding, (value) => value && findTickets());

async function attach(ticket: CustomerTicket): Promise<void> {
    if (!detail.value) {
        return;
    }

    try {
        show(await attachTicket(detail.value.customer.id, ticket.uuid));
        adding.value = false;
        await search();
    } catch (e) {
        failed(e);
    }
}

async function detach(ticket: CustomerTicket): Promise<void> {
    if (!detail.value || !confirm(t('customers.detachConfirm', { number: ticket.fullNumber }))) {
        return;
    }

    try {
        show(await detachTicket(detail.value.customer.id, ticket.uuid));
        await search();
    } catch (e) {
        failed(e);
    }
}
</script>

<template>
    <div class="grid h-full min-h-0 md:grid-cols-[22rem_1fr]">
        <section class="flex min-h-0 flex-col border-r bg-white dark:bg-slate-900">
            <div class="flex shrink-0 gap-2 border-b p-2">
                <div class="relative min-w-0 flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="query"
                        type="search"
                        :placeholder="t('customers.search')"
                        class="h-11 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-11 text-base outline-none focus:border-[#00056a] focus:ring-2 focus:ring-[#00056a]/20"
                    />
                </div>
                <Button class="h-11 bg-[#00056a]" @click="(creating = true), (detail = null)"><Plus class="size-4" /> {{ t('customers.new') }}</Button>
            </div>
            <div class="min-h-0 flex-1 divide-y overflow-y-auto">
                <button
                    v-for="customer in customers"
                    :key="customer.id"
                    type="button"
                    class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50"
                    :class="detail?.customer.id === customer.id ? 'bg-[#00056a]/10' : ''"
                    @click="open(customer)"
                >
                    <span class="shrink-0 rounded bg-[#00056a] px-2 py-0.5 text-sm font-bold text-white">{{ customer.code }}</span>
                    <span class="min-w-0 flex-1 truncate font-medium">{{ customer.name }}</span>
                    <span v-if="customer.pendingCount" class="shrink-0 rounded-full bg-amber-100 px-2 text-xs font-semibold text-amber-800">{{ customer.pendingCount }}</span>
                </button>
                <p v-if="!customers.length" class="p-6 text-center text-sm text-slate-500">{{ t('customers.none') }}</p>
            </div>
        </section>

        <section class="min-h-0 overflow-y-auto bg-slate-50 p-4">
            <div v-if="creating" class="mx-auto max-w-lg rounded-xl bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-lg font-bold text-[#00056a]">{{ t('customers.new') }}</h2>
                <CustomerForm @saved="saved" @cancel="creating = false" />
            </div>

            <div v-else-if="detail" class="mx-auto max-w-2xl space-y-4">
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <CustomerForm v-if="editing" :customer="detail.customer" @saved="saved" @cancel="editing = false" />
                    <div v-else class="flex items-start gap-3">
                        <span class="shrink-0 rounded-lg bg-[#00056a] px-3 py-1 text-lg font-bold text-white">{{ detail.customer.code }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-lg font-bold">{{ detail.customer.name }}</p>
                            <p class="text-sm text-slate-500">
                                {{ [detail.customer.taxId && `NIF ${detail.customer.taxId}`, detail.customer.phone, detail.customer.email].filter(Boolean).join(' · ') || t('customers.noFiscal') }}
                            </p>
                            <p v-if="detail.customer.address" class="text-sm text-slate-500">{{ detail.customer.address }}</p>
                        </div>
                        <Button variant="outline" size="icon" class="size-10" :title="t('common.edit')" @click="editing = true"><Pencil class="size-4" /></Button>
                    </div>
                </div>

                <div v-if="detail.notes.length" class="rounded-xl border-2 border-amber-300 bg-white shadow-sm">
                    <h3 class="flex items-center gap-2 border-b px-4 py-3 font-bold"><ClipboardSignature class="size-5 text-amber-600" /> {{ t('deliveryNotes.pending') }}</h3>
                    <div class="divide-y">
                        <label v-for="note in detail.notes" :key="note.uuid" class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                            <Checkbox :model-value="selectedNotes.has(note.uuid)" @update:model-value="(value) => toggleNote(note.uuid, value === true)" />
                            <span class="min-w-0 flex-1">
                                <span class="font-medium">{{ formatDateTime(note.issuedAt) }}</span>
                                <span class="block truncate text-xs text-slate-500">
                                    <template v-if="note.tableLabel">{{ note.tableLabel }} · </template>{{ note.lines.map((line) => tr(line.name)).join(', ') }}
                                    <template v-if="note.collecting"> · {{ t('deliveryNotes.collecting') }}</template>
                                </span>
                            </span>
                            <span class="font-semibold tabular-nums">{{ formatMoney(note.total) }}</span>
                            <Button variant="outline" size="sm" class="h-9" @click.prevent="reprintNote(note)"><Printer class="size-4" /> {{ t('deliveryNotes.reprint') }}</Button>
                        </label>
                    </div>
                    <div class="flex items-center gap-3 border-t bg-amber-50 px-4 py-3">
                        <span class="flex-1 text-sm text-slate-600">{{ t('deliveryNotes.selected', { count: selectedNotes.size }) }}</span>
                        <span class="text-xl font-bold text-amber-800 tabular-nums">{{ formatMoney(selectedNotesTotal) }}</span>
                        <Button class="h-12 bg-emerald-600 hover:bg-emerald-700" :disabled="!selectedNotes.size || busy" @click="collect">
                            <Wallet class="size-5" /> {{ t('deliveryNotes.collect') }}
                        </Button>
                    </div>
                </div>

                <div class="rounded-xl bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b px-4 py-3">
                        <h3 class="flex-1 font-bold">{{ t('customers.pending') }}</h3>
                        <Button variant="outline" class="h-10" @click="adding = true"><Plus class="size-4" /> {{ t('customers.addTicket') }}</Button>
                    </div>
                    <div v-if="detail.pending.length" class="flex flex-wrap items-center gap-2 border-b bg-slate-50 px-4 py-2 text-sm">
                        <Button variant="outline" size="sm" class="h-9" @click="selectRange('', '')">{{ t('customers.rangeAll') }}</Button>
                        <Button variant="outline" size="sm" class="h-9" @click="selectMonth(0)">{{ t('customers.rangeThisMonth') }}</Button>
                        <Button variant="outline" size="sm" class="h-9" @click="selectMonth(-1)">{{ t('customers.rangeLastMonth') }}</Button>
                        <span class="ml-auto flex items-center gap-1">
                            <input :value="rangeFrom" type="date" class="h-9 rounded-lg border px-2" :aria-label="t('customers.rangeFrom')" @change="selectRange(($event.target as HTMLInputElement).value, rangeTo)" />
                            –
                            <input :value="rangeTo" type="date" class="h-9 rounded-lg border px-2" :aria-label="t('customers.rangeTo')" @change="selectRange(rangeFrom, ($event.target as HTMLInputElement).value)" />
                        </span>
                    </div>
                    <div class="divide-y">
                        <label v-for="ticket in detail.pending" :key="ticket.uuid" class="flex cursor-pointer items-center gap-3 px-4 py-2.5">
                            <Checkbox :model-value="selected.has(ticket.uuid)" @update:model-value="(value) => toggle(ticket.uuid, value === true)" />
                            <span class="min-w-0 flex-1">
                                <span class="font-medium">{{ ticket.fullNumber }}</span>
                                <span class="block text-xs text-slate-500">{{ formatDateTime(ticket.issuedAt) }}<template v-if="ticket.tableLabel"> · {{ ticket.tableLabel }}</template></span>
                            </span>
                            <span class="font-semibold tabular-nums">{{ formatMoney(ticket.total) }}</span>
                            <button type="button" class="flex size-8 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100" :title="t('customers.detach')" @click.prevent="detach(ticket)">
                                <X class="size-4" />
                            </button>
                        </label>
                        <p v-if="!detail.pending.length" class="p-6 text-center text-sm text-slate-500">{{ t('customers.noPending') }}</p>
                    </div>
                    <div v-if="detail.pending.length" class="flex items-center gap-3 border-t bg-slate-50 px-4 py-3">
                        <span class="flex-1 text-sm text-slate-600">{{ t('customers.selected', { count: selected.size }) }}</span>
                        <span class="text-xl font-bold text-[#00056a] tabular-nums">{{ formatMoney(selectedTotal) }}</span>
                        <Button class="h-12 bg-[#00056a]" :disabled="!selected.size || busy" @click="printSummary">
                            <Printer class="size-5" /> {{ busy ? t('cashier.sending') : t('customers.printSummary') }}
                        </Button>
                    </div>
                </div>

                <div v-if="detail.statements.length" class="rounded-xl bg-white shadow-sm">
                    <h3 class="border-b px-4 py-3 font-bold">{{ t('customers.statements') }}</h3>
                    <div class="divide-y">
                        <div v-for="statement in detail.statements" :key="statement.id" class="flex items-center gap-3 px-4 py-2.5">
                            <FileText class="size-5 shrink-0 text-slate-400" />
                            <span class="min-w-0 flex-1">
                                <span class="font-medium">{{ statement.label }}</span>
                                <span class="block text-xs text-slate-500">
                                    {{ formatDateTime(statement.issuedAt) }}<template v-if="statement.invoice"> · {{ t('customers.invoiced', { number: statement.invoice }) }}</template>
                                </span>
                            </span>
                            <span class="font-semibold tabular-nums">{{ formatMoney(statement.total) }}</span>
                            <Button variant="outline" size="sm" class="h-9" @click="reprint(statement.id)"><Printer class="size-4" /> {{ t('customers.reprint') }}</Button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="flex h-full flex-col items-center justify-center gap-3 text-center text-slate-500">
                <UserRound class="size-14 text-slate-300" />
                <p class="max-w-sm text-sm">{{ t('customers.help') }}</p>
            </div>
        </section>

        <Dialog v-model:open="adding">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ t('customers.addTicket') }}</DialogTitle>
                    <DialogDescription>{{ t('customers.addTicketHelp') }}</DialogDescription>
                </DialogHeader>
                <form class="flex gap-2" @submit.prevent="findTickets">
                    <input
                        v-model="ticketQuery"
                        type="search"
                        :placeholder="t('customers.ticketSearch')"
                        class="h-11 min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 text-base outline-none focus:border-[#00056a]"
                    />
                    <Button type="submit" class="h-11 bg-[#00056a]"><Search class="size-4" /></Button>
                </form>
                <div class="max-h-80 divide-y overflow-y-auto rounded-lg border">
                    <button v-for="ticket in ticketResults" :key="ticket.uuid" type="button" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50" @click="attach(ticket)">
                        <span class="min-w-0 flex-1">
                            <span class="font-medium">{{ ticket.fullNumber }}</span>
                            <span class="block text-xs text-slate-500">{{ formatDateTime(ticket.issuedAt) }}<template v-if="ticket.tableLabel"> · {{ ticket.tableLabel }}</template></span>
                        </span>
                        <span v-if="ticket.customerCode" class="rounded bg-slate-100 px-1.5 text-xs font-semibold text-slate-600">{{ ticket.customerCode }}</span>
                        <span class="font-semibold tabular-nums">{{ formatMoney(ticket.total) }}</span>
                    </button>
                    <p v-if="!ticketResults.length" class="p-4 text-center text-sm text-slate-500">{{ t('customers.noTickets') }}</p>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
