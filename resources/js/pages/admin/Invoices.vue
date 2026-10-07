<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Mail } from '@lucide/vue';
import { ref, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import Pagination from '@/components/admin/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDateTime, money } from '@/lib/format';
import { invoices as invoicesRoute } from '@/routes/admin';
import { pdf, resend } from '@/routes/admin/invoices';
import { show } from '@/routes/admin/tickets';

type Invoice = {
    id: number;
    ticketId: number;
    fullNumber: string;
    ticketNumber: string;
    customerName: string;
    customerTaxId: string;
    customerEmail: string | null;
    total: number;
    issuedAt: string;
    emailedAt: string | null;
};

const props = defineProps<{
    invoices: { data: Invoice[]; links: { url: string | null; label: string; active: boolean }[] };
    search: string;
}>();

const { t } = useI18n();
const search = ref(props.search);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(invoicesRoute.url(), { search: value || undefined }, { preserveState: true, replace: true }), 300);
});

defineOptions({ layout: { breadcrumbs: [{ title: 'Factures', href: invoicesRoute() }] } });
</script>

<template>
    <Head :title="t('admin.invoices.title')" />
    <PageHeader :title="t('admin.invoices.title')">
        <Input v-model="search" :placeholder="t('common.search')" class="w-64" />
    </PageHeader>

    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-left text-sm">
            <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                <tr>
                    <th class="px-3 py-2">{{ t('admin.tickets.number') }}</th>
                    <th class="px-3 py-2">{{ t('common.date') }}</th>
                    <th class="px-3 py-2">{{ t('admin.invoices.customer') }}</th>
                    <th class="px-3 py-2">{{ t('admin.tickets.title') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('common.total') }}</th>
                    <th class="px-3 py-2" />
                </tr>
            </thead>
            <tbody>
                <tr v-for="invoice in invoices.data" :key="invoice.id" class="border-t">
                    <td class="px-3 py-2 font-medium">{{ invoice.fullNumber }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(invoice.issuedAt) }}</td>
                    <td class="px-3 py-2">
                        {{ invoice.customerName }}
                        <div class="text-xs text-muted-foreground">{{ invoice.customerTaxId }} {{ invoice.customerEmail ? `· ${invoice.customerEmail}` : '' }}</div>
                    </td>
                    <td class="px-3 py-2"><Link :href="show(invoice.ticketId)" class="hover:underline">{{ invoice.ticketNumber }}</Link></td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money(invoice.total) }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <Button variant="ghost" size="icon" as-child>
                            <a :href="pdf.url(invoice.id)" target="_blank"><FileText class="size-4" /></a>
                        </Button>
                        <Button
                            v-if="invoice.customerEmail"
                            variant="ghost"
                            size="icon"
                            :title="invoice.emailedAt ? `${t('admin.invoices.sent')} ${formatDateTime(invoice.emailedAt)}` : ''"
                            @click="router.post(resend.url(invoice.id), {}, { preserveScroll: true })"
                        >
                            <Mail class="size-4" :class="invoice.emailedAt ? 'text-emerald-600' : ''" />
                        </Button>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="invoices.data.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
    </div>
    <Pagination :links="invoices.links" />
</template>
