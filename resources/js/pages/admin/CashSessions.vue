<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import Pagination from '@/components/admin/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n';
import { formatDateTime, money } from '@/lib/format';
import { cashSessions as cashSessionsRoute } from '@/routes/admin';
import { pdf } from '@/routes/admin/cash-sessions';

type Session = {
    id: number;
    device: string | null;
    openedAt: string;
    openedBy: string | null;
    closedAt: string | null;
    closedBy: string | null;
    zNumber: number | null;
    openingFloat: number;
    expectedCash: number | null;
    countedCash: number | null;
    difference: number | null;
    total: number;
    tickets: number;
    summary: { by_method?: Record<string, number>; first_ticket?: string | null; last_ticket?: string | null } | null;
};

defineProps<{ sessions: { data: Session[]; links: { url: string | null; label: string; active: boolean }[] } }>();

const { t } = useI18n();

defineOptions({ layout: { breadcrumbs: [{ title: 'Tancaments de caixa', href: cashSessionsRoute() }] } });
</script>

<template>
    <Head :title="t('admin.cashSessions.title')" />
    <PageHeader :title="t('admin.cashSessions.title')" :description="t('admin.cashSessions.description')" />

    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-left text-sm">
            <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                <tr>
                    <th class="px-3 py-2">Z</th>
                    <th class="px-3 py-2">{{ t('admin.cashSessions.device') }}</th>
                    <th class="px-3 py-2">{{ t('admin.cashSessions.openedAt') }}</th>
                    <th class="px-3 py-2">{{ t('admin.cashSessions.closedAt') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('cashier.ticketCount') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('cashier.cash') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('cashier.card') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('common.total') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('cashier.difference') }}</th>
                    <th class="px-3 py-2" />
                </tr>
            </thead>
            <tbody>
                <tr v-for="session in sessions.data" :key="session.id" class="border-t">
                    <td class="px-3 py-2 font-medium">{{ session.zNumber ?? '—' }}</td>
                    <td class="px-3 py-2">{{ session.device ?? '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ formatDateTime(session.openedAt) }}
                        <div class="text-xs text-muted-foreground">{{ session.openedBy }}</div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <template v-if="session.closedAt">
                            {{ formatDateTime(session.closedAt) }}
                            <div class="text-xs text-muted-foreground">{{ session.closedBy }}</div>
                        </template>
                        <Badge v-else>{{ t('admin.cashSessions.open') }}</Badge>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ session.tickets }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money(session.summary?.by_method?.cash ?? 0) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ money(session.summary?.by_method?.card ?? 0) }}</td>
                    <td class="px-3 py-2 text-right font-medium tabular-nums">{{ money(session.total) }}</td>
                    <td
                        class="px-3 py-2 text-right tabular-nums"
                        :class="session.difference ? (session.difference < 0 ? 'text-destructive' : 'text-amber-600') : ''"
                    >
                        {{ session.difference === null ? '—' : money(session.difference) }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <Button v-if="session.closedAt" variant="ghost" size="icon" as-child>
                            <a :href="pdf.url(session.id)" target="_blank"><FileText class="size-4" /></a>
                        </Button>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="sessions.data.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
    </div>
    <Pagination :links="sessions.links" />
</template>
