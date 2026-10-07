<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Mail } from '@lucide/vue';
import QRCode from 'qrcode';
import { onMounted, ref } from 'vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import TextArea from '@/components/admin/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDateTime, money } from '@/lib/format';
import { tickets as ticketsRoute } from '@/routes/admin';
import { pdf as invoicePdf, resend } from '@/routes/admin/invoices';
import { invoice as issueInvoice, pdf } from '@/routes/admin/tickets';

type Ticket = {
    id: number;
    fullNumber: string;
    issuedAt: string;
    tableLabel: string | null;
    waiter: string | null;
    cashier: string | null;
    subtotal: number;
    surchargeRate: number;
    surchargeAmount: number;
    discountTotal: number;
    total: number;
    vatBreakdown: { rate: number; base: number; vat: number; total: number }[];
    issuer: Record<string, string>;
    hash: string | null;
    previousHash: string | null;
    verifactu: { simulated?: boolean; status?: string; qr_url?: string; generated_at?: string } | null;
    publicUrl: string;
    lines: { name: string; quantity: number; unitPrice: number; vatRate: number; discountAmount: number; total: number; modifiers: { name: unknown; priceDelta?: number }[] }[];
    payments: { method: 'cash' | 'card'; amount: number; tendered: number | null; change: number | null }[];
    invoice: {
        id: number;
        fullNumber: string;
        customerName: string;
        customerTaxId: string;
        customerAddress: string;
        customerEmail: string | null;
        issuedAt: string;
        emailedAt: string | null;
    } | null;
};

const props = defineProps<{ ticket: Ticket }>();
const { t, tr } = useI18n();

const verifactuQr = ref('');
const invoiceQr = ref('');

onMounted(async () => {
    if (props.ticket.verifactu?.qr_url) {
        verifactuQr.value = await QRCode.toDataURL(props.ticket.verifactu.qr_url, { margin: 1, width: 160 });
    }

    invoiceQr.value = await QRCode.toDataURL(props.ticket.publicUrl, { margin: 1, width: 160 });
});

const form = useForm({ customer_name: '', customer_tax_id: '', customer_address: '', customer_email: '' });

function submitInvoice(): void {
    form.post(issueInvoice.url(props.ticket.id), { preserveScroll: true });
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Tiquets', href: ticketsRoute() }] } });
</script>

<template>
    <Head :title="ticket.fullNumber" />
    <PageHeader :title="`${t('admin.tickets.number')} ${ticket.fullNumber}`" :description="formatDateTime(ticket.issuedAt)">
        <Button variant="outline" as-child>
            <Link :href="ticketsRoute()"><ArrowLeft class="size-4" /> {{ t('common.back') }}</Link>
        </Button>
        <Button variant="outline" as-child>
            <a :href="pdf.url(ticket.id)" target="_blank"><FileText class="size-4" /> {{ t('admin.tickets.viewPdf') }}</a>
        </Button>
    </PageHeader>

    <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <section class="space-y-4">
            <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">{{ t('admin.tickets.table') }}</div>{{ ticket.tableLabel ?? '—' }}</div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">{{ t('admin.tickets.waiter') }}</div>{{ ticket.waiter ?? '—' }}</div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">{{ t('admin.tickets.cashier') }}</div>{{ ticket.cashier ?? '—' }}</div>
                <div class="rounded-lg border p-3"><div class="text-xs text-muted-foreground">{{ t('common.total') }}</div><span class="font-semibold">{{ money(ticket.total) }}</span></div>
            </div>

            <div class="overflow-hidden rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ t('admin.tickets.lines') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('order.quantity') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('common.price') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('common.vat') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('common.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in ticket.lines" :key="index" class="border-t">
                            <td class="px-3 py-2">
                                {{ line.name }}
                                <div v-if="line.modifiers.length" class="text-xs text-muted-foreground">
                                    {{ line.modifiers.map((m) => tr(m.name as never)).join(', ') }}
                                </div>
                                <div v-if="line.discountAmount" class="text-xs text-emerald-600">-{{ money(line.discountAmount) }}</div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ line.quantity }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ money(line.unitPrice) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ line.vatRate }}%</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ money(line.total) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/20 text-sm">
                        <tr v-if="ticket.discountTotal">
                            <td colspan="4" class="px-3 py-1 text-right">{{ t('cashier.discounts') }}</td>
                            <td class="px-3 py-1 text-right tabular-nums">-{{ money(ticket.discountTotal) }}</td>
                        </tr>
                        <tr v-if="ticket.surchargeAmount || ticket.surchargeRate">
                            <td colspan="4" class="px-3 py-1 text-right">{{ t('cashier.surcharge', { percent: ticket.surchargeRate }) }}</td>
                            <td class="px-3 py-1 text-right tabular-nums">{{ money(ticket.surchargeAmount) }}</td>
                        </tr>
                        <tr class="font-semibold">
                            <td colspan="4" class="px-3 py-2 text-right">{{ t('common.total') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ money(ticket.total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border p-3 text-sm">
                    <div class="mb-2 font-medium">{{ t('cashier.vatBreakdown') }}</div>
                    <div v-for="row in ticket.vatBreakdown" :key="row.rate" class="flex justify-between tabular-nums">
                        <span>{{ row.rate }}%</span>
                        <span>{{ t('cashier.base') }} {{ money(row.base) }} · {{ t('cashier.quota') }} {{ money(row.vat) }}</span>
                    </div>
                </div>
                <div class="rounded-xl border p-3 text-sm">
                    <div class="mb-2 font-medium">{{ t('cashier.byMethod') }}</div>
                    <div v-for="(payment, index) in ticket.payments" :key="index" class="flex justify-between tabular-nums">
                        <span>{{ t(`cashier.${payment.method}`) }}</span>
                        <span>
                            {{ money(payment.amount) }}
                            <span v-if="payment.tendered" class="text-xs text-muted-foreground">
                                ({{ t('cashier.tendered') }} {{ money(payment.tendered) }}, {{ t('cashier.change') }} {{ money(payment.change ?? 0) }})
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border p-3 text-xs break-all text-muted-foreground">
                <div><span class="font-medium text-foreground">{{ t('admin.tickets.hash') }}:</span> {{ ticket.hash ?? '—' }}</div>
                <div><span class="font-medium text-foreground">{{ t('admin.tickets.previousHash') }}:</span> {{ ticket.previousHash ?? '—' }}</div>
            </div>
        </section>

        <aside class="space-y-4">
            <div class="rounded-xl border p-4">
                <h2 class="mb-3 font-medium">{{ t('admin.tickets.invoice') }}</h2>
                <div v-if="ticket.invoice" class="space-y-1 text-sm">
                    <div class="text-lg font-semibold">{{ ticket.invoice.fullNumber }}</div>
                    <div>{{ ticket.invoice.customerName }} · {{ ticket.invoice.customerTaxId }}</div>
                    <div class="text-muted-foreground">{{ ticket.invoice.customerAddress }}</div>
                    <div v-if="ticket.invoice.customerEmail" class="text-muted-foreground">
                        {{ ticket.invoice.customerEmail }}
                        <span v-if="ticket.invoice.emailedAt"> · {{ t('admin.invoices.sent') }} {{ formatDateTime(ticket.invoice.emailedAt) }}</span>
                    </div>
                    <div class="flex gap-2 pt-2">
                        <Button size="sm" variant="outline" as-child>
                            <a :href="invoicePdf.url(ticket.invoice.id)" target="_blank"><FileText class="size-4" /> PDF</a>
                        </Button>
                        <Button
                            v-if="ticket.invoice.customerEmail"
                            size="sm"
                            variant="outline"
                            @click="router.post(resend.url(ticket.invoice.id), {}, { preserveScroll: true })"
                        >
                            <Mail class="size-4" /> Email
                        </Button>
                    </div>
                </div>
                <form v-else class="space-y-3" @submit.prevent="submitInvoice">
                    <Field :label="t('invoiceRequest.customerName')" :error="form.errors.customer_name">
                        <Input v-model="form.customer_name" required />
                    </Field>
                    <Field :label="t('invoiceRequest.taxId')" :error="form.errors.customer_tax_id">
                        <Input v-model="form.customer_tax_id" required />
                    </Field>
                    <Field :label="t('invoiceRequest.address')" :error="form.errors.customer_address">
                        <TextArea v-model="form.customer_address" :rows="2" />
                    </Field>
                    <Field :label="t('common.email')" :error="form.errors.customer_email">
                        <Input v-model="form.customer_email" type="email" />
                    </Field>
                    <Button type="submit" class="w-full" :disabled="form.processing">{{ t('admin.tickets.requestInvoice') }}</Button>
                </form>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl border p-3 text-center text-xs">
                    <img v-if="verifactuQr" :src="verifactuQr" alt="" class="mx-auto size-28" />
                    <div class="mt-1 text-muted-foreground">{{ t('admin.tickets.verifactu') }}</div>
                </div>
                <div class="rounded-xl border p-3 text-center text-xs">
                    <img v-if="invoiceQr" :src="invoiceQr" alt="" class="mx-auto size-28" />
                    <a :href="ticket.publicUrl" target="_blank" class="mt-1 block text-muted-foreground hover:underline">{{ t('cashier.invoiceQr') }}</a>
                </div>
            </div>
        </aside>
    </div>
</template>
