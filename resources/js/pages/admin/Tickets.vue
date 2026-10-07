<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { reactive, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import Pagination from '@/components/admin/Pagination.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDateTime, money } from '@/lib/format';
import { tickets as ticketsRoute } from '@/routes/admin';
import { pdf, show } from '@/routes/admin/tickets';

type Ticket = {
    id: number;
    fullNumber: string;
    issuedAt: string;
    tableLabel: string | null;
    waiter: string | null;
    cashier: string | null;
    total: number;
    payments: { method: 'cash' | 'card'; amount: number }[];
    invoice: string | null;
};

const props = defineProps<{
    tickets: { data: Ticket[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { from: string; to: string; search?: string; method?: string };
    totals: { count: number; total: number };
}>();

const { t } = useI18n();
const filters = reactive({
    from: props.filters.from,
    to: props.filters.to,
    search: props.filters.search ?? '',
    method: (props.filters.method ?? null) as string | null,
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            ticketsRoute.url(),
            { from: filters.from, to: filters.to, search: filters.search || undefined, method: filters.method || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

defineOptions({ layout: { breadcrumbs: [{ title: 'Tiquets', href: ticketsRoute() }] } });
</script>

<template>
    <Head :title="t('admin.tickets.title')" />
    <PageHeader :title="t('admin.tickets.title')" :description="t('admin.tickets.description')">
        <div class="rounded-lg border px-4 py-2 text-right">
            <div class="text-xs text-muted-foreground">{{ totals.count }} {{ t('cashier.ticketCount').toLowerCase() }}</div>
            <div class="text-lg font-semibold tabular-nums">{{ money(totals.total) }}</div>
        </div>
    </PageHeader>

    <div class="mb-4 flex flex-wrap gap-2">
        <Input v-model="filters.from" type="date" class="max-w-40" />
        <Input v-model="filters.to" type="date" class="max-w-40" />
        <Input v-model="filters.search" :placeholder="t('common.search')" class="max-w-56" />
        <SelectInput
            v-model="filters.method"
            class="max-w-40"
            :placeholder="t('common.all')"
            :options="[
                { value: 'cash', label: t('cashier.cash') },
                { value: 'card', label: t('cashier.card') },
            ]"
        />
    </div>

    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-left text-sm">
            <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                <tr>
                    <th class="px-3 py-2">{{ t('admin.tickets.number') }}</th>
                    <th class="px-3 py-2">{{ t('common.date') }}</th>
                    <th class="px-3 py-2">{{ t('admin.tickets.table') }}</th>
                    <th class="px-3 py-2">{{ t('admin.tickets.waiter') }}</th>
                    <th class="px-3 py-2">{{ t('admin.tickets.method') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('common.total') }}</th>
                    <th class="px-3 py-2" />
                </tr>
            </thead>
            <tbody>
                <tr v-for="ticket in tickets.data" :key="ticket.id" class="border-t hover:bg-muted/30">
                    <td class="px-3 py-2 font-medium">
                        <Link :href="show(ticket.id)" class="hover:underline">{{ ticket.fullNumber }}</Link>
                        <Badge v-if="ticket.invoice" variant="outline" class="ml-2">{{ ticket.invoice }}</Badge>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(ticket.issuedAt) }}</td>
                    <td class="px-3 py-2">{{ ticket.tableLabel ?? '—' }}</td>
                    <td class="px-3 py-2">{{ ticket.waiter ?? '—' }}</td>
                    <td class="px-3 py-2">
                        <Badge v-for="(payment, index) in ticket.payments" :key="index" variant="secondary" class="mr-1">
                            {{ t(`cashier.${payment.method}`) }} {{ ticket.payments.length > 1 ? money(payment.amount) : '' }}
                        </Badge>
                    </td>
                    <td class="px-3 py-2 text-right font-medium tabular-nums">{{ money(ticket.total) }}</td>
                    <td class="px-3 py-2 text-right">
                        <a :href="pdf.url(ticket.id)" target="_blank" class="text-muted-foreground hover:text-foreground"><FileText class="size-4" /></a>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="tickets.data.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
    </div>
    <Pagination :links="tickets.links" />
</template>
