<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Clock, Download, Languages } from '@lucide/vue';
import Field from '@/components/admin/Field.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDateTime, money } from '@/lib/format';
import { store, pdf } from '@/routes/invoice-request';

const props = defineProps<{
    token: string;
    business: { name: string; logoUrl: string | null };
    ticket: { fullNumber: string; issuedAt: string; total: number } | null;
    invoice: { fullNumber: string } | null;
}>();

const { t, locale, setLocale } = useI18n();

const form = useForm({
    customer_name: '',
    customer_tax_id: '',
    address: '',
    postal_code: '',
    city: '',
    customer_email: '',
});

function submit(): void {
    form.transform((data) => ({ ...data, customer_email: data.customer_email || null })).post(store.url(props.token), { preserveScroll: true });
}

async function toggleLocale(): Promise<void> {
    await setLocale(locale.value === 'ca' ? 'es' : 'ca');
}
</script>

<template>
    <Head :title="t('invoiceRequest.title')" />
    <div class="min-h-svh bg-muted/40 px-4 py-8">
        <div class="mx-auto max-w-lg">
            <div class="mb-6 flex items-center justify-between">
                <img v-if="business.logoUrl" :src="business.logoUrl" :alt="business.name" class="h-12 w-auto" />
                <span v-else class="font-semibold">{{ business.name }}</span>
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="size-4" /> {{ locale === 'ca' ? 'Castellano' : 'Català' }}
                </Button>
            </div>

            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <h1 class="text-xl font-semibold">{{ t('invoiceRequest.title') }}</h1>

                <div v-if="!ticket" class="mt-6 flex flex-col items-center gap-3 py-6 text-center">
                    <Clock class="size-12 text-amber-500" />
                    <p class="font-medium">{{ t('invoiceRequest.pendingTitle') }}</p>
                    <p class="text-sm text-muted-foreground">{{ t('invoiceRequest.pendingHelp') }}</p>
                    <Button variant="outline" @click="router.reload()">{{ t('sync.retry') }}</Button>
                </div>

                <template v-else>
                    <div class="mt-4 grid grid-cols-2 gap-3 rounded-lg bg-muted/50 p-3 text-sm">
                        <div>
                            <div class="text-xs text-muted-foreground">{{ t('admin.tickets.number') }}</div>
                            <div class="font-medium">{{ ticket.fullNumber }}</div>
                            <div class="text-xs text-muted-foreground">{{ formatDateTime(ticket.issuedAt) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-muted-foreground">{{ t('invoiceRequest.ticketTotal') }}</div>
                            <div class="text-lg font-semibold tabular-nums">{{ money(ticket.total) }}</div>
                        </div>
                    </div>

                    <div v-if="invoice" class="mt-6 flex flex-col items-center gap-3 py-4 text-center">
                        <CheckCircle2 class="size-12 text-emerald-500" />
                        <p class="font-medium">{{ t('invoiceRequest.done', { number: invoice.fullNumber }) }}</p>
                        <Button as-child size="lg">
                            <a :href="pdf.url(token)"><Download class="size-4" /> {{ t('invoiceRequest.downloadPdf') }}</a>
                        </Button>
                    </div>

                    <form v-else class="mt-6 space-y-4" @submit.prevent="submit">
                        <p class="text-sm text-muted-foreground">{{ t('invoiceRequest.description', { number: ticket.fullNumber }) }}</p>
                        <Field :label="t('invoiceRequest.customerName')" :error="form.errors.customer_name">
                            <Input v-model="form.customer_name" required autocomplete="organization" />
                        </Field>
                        <Field :label="t('invoiceRequest.taxId')" :error="form.errors.customer_tax_id">
                            <Input v-model="form.customer_tax_id" required autocapitalize="characters" />
                        </Field>
                        <Field :label="t('invoiceRequest.address')" :error="form.errors.address">
                            <Input v-model="form.address" required autocomplete="street-address" />
                        </Field>
                        <div class="grid grid-cols-[8rem_1fr] gap-3">
                            <Field :label="t('invoiceRequest.postalCode')" :error="form.errors.postal_code">
                                <Input v-model="form.postal_code" required inputmode="numeric" autocomplete="postal-code" />
                            </Field>
                            <Field :label="t('invoiceRequest.city')" :error="form.errors.city">
                                <Input v-model="form.city" required autocomplete="address-level2" />
                            </Field>
                        </div>
                        <Field :label="t('invoiceRequest.email')" :error="form.errors.customer_email">
                            <Input v-model="form.customer_email" type="email" autocomplete="email" />
                        </Field>
                        <Button type="submit" size="lg" class="w-full" :disabled="form.processing">{{ t('invoiceRequest.submit') }}</Button>
                    </form>
                </template>
            </div>
        </div>
    </div>
</template>
