<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarDays,
    Euro,
    LayoutPanelTop,
    Receipt,
    Truck,
    UtensilsCrossed,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n';
import { formatDate, formatTime, money } from '@/lib/format';
import { dashboard, tpv } from '@/routes';
import { suppliers, time } from '@/routes/admin';

const props = defineProps<{
    stats: {
        salesToday: number;
        ticketsToday: number;
        openTables: number;
        reservationsToday: number;
        pendingSupplierInvoices: number;
    };
    working: { name: string; state: 'in' | 'break'; since: string | null }[];
    alerts: { name: string; userId: number; type: 'missing_clock_out' | 'off_shift'; date: string; at: string }[];
    daily: { date: string; total: number }[];
}>();

const { t } = useI18n();

const maxDaily = computed(() => Math.max(1, ...props.daily.map((d) => d.total)));

const cards = computed(() => [
    { label: t('admin.dashboard.salesToday'), value: money(props.stats.salesToday), icon: Euro },
    { label: t('admin.dashboard.ticketsToday'), value: String(props.stats.ticketsToday), icon: Receipt },
    { label: t('admin.dashboard.openTables'), value: String(props.stats.openTables), icon: UtensilsCrossed },
    { label: t('admin.dashboard.reservationsToday'), value: `${props.stats.reservationsToday} ${t('common.persons')}`, icon: CalendarDays },
]);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panell', href: dashboard() }],
    },
});
</script>

<template>
    <Head :title="t('admin.dashboard.title')" />

    <div class="mx-auto w-full max-w-7xl px-4 py-6">
        <PageHeader :title="t('admin.dashboard.title')">
            <Button as-child size="lg">
                <a :href="tpv().url">
                    <LayoutPanelTop class="size-4" />
                    {{ t('admin.dashboard.openTpv') }}
                </a>
            </Button>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="card in cards"
                :key="card.label"
                class="rounded-xl border bg-card p-4 shadow-xs"
            >
                <div class="flex items-center justify-between text-sm text-muted-foreground">
                    {{ card.label }}
                    <component :is="card.icon" class="size-4" />
                </div>
                <div class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ card.value }}
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border bg-card p-4 lg:col-span-2">
                <h2 class="mb-4 text-sm font-medium">
                    {{ t('admin.dashboard.last7days') }}
                </h2>
                <div class="flex h-48 items-end gap-2">
                    <div
                        v-for="day in daily"
                        :key="day.date"
                        class="flex flex-1 flex-col items-center gap-1"
                    >
                        <span class="text-[11px] text-muted-foreground tabular-nums">
                            {{ money(day.total, false) }}
                        </span>
                        <div
                            class="w-full rounded-t-md bg-primary/80"
                            :style="{ height: `${Math.max(2, (day.total / maxDaily) * 140)}px` }"
                        />
                        <span class="text-xs text-muted-foreground">
                            {{ formatDate(day.date, { weekday: 'short', day: 'numeric' }) }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card p-4">
                <h2 class="mb-3 text-sm font-medium">
                    {{ t('admin.dashboard.working') }}
                </h2>
                <p v-if="working.length === 0" class="text-sm text-muted-foreground">
                    {{ t('common.empty') }}
                </p>
                <ul class="space-y-2">
                    <li
                        v-for="person in working"
                        :key="person.name"
                        class="flex items-center justify-between text-sm"
                    >
                        <span class="font-medium">{{ person.name }}</span>
                        <span class="flex items-center gap-2">
                            <Badge :variant="person.state === 'in' ? 'default' : 'secondary'">
                                {{ t(`clock.status.${person.state}`) }}
                            </Badge>
                            <span class="text-xs text-muted-foreground">
                                {{ formatTime(person.since) }}
                            </span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border bg-card p-4 lg:col-span-2">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-medium">
                    <AlertTriangle class="size-4 text-amber-500" />
                    {{ t('admin.dashboard.alerts') }}
                </h2>
                <p v-if="alerts.length === 0" class="text-sm text-muted-foreground">
                    {{ t('admin.dashboard.noAlerts') }}
                </p>
                <ul class="divide-y text-sm">
                    <li v-for="(alert, index) in alerts" :key="index" class="py-2">
                        <Link
                            :href="time({ query: { user: alert.userId, from: alert.date, to: alert.date } })"
                            class="hover:underline"
                        >
                            {{
                                alert.type === 'missing_clock_out'
                                    ? t('admin.dashboard.missingClockOut', { name: alert.name, date: formatDate(alert.date) })
                                    : t('admin.dashboard.offShift', { name: alert.name, date: formatDate(alert.date) })
                            }}
                        </Link>
                    </li>
                </ul>
            </section>

            <Link
                :href="suppliers()"
                class="flex items-center justify-between rounded-xl border bg-card p-4 transition hover:bg-muted/40"
            >
                <div>
                    <div class="text-sm text-muted-foreground">
                        {{ t('admin.dashboard.pendingInvoices') }}
                    </div>
                    <div class="mt-1 text-2xl font-semibold">
                        {{ stats.pendingSupplierInvoices }}
                    </div>
                </div>
                <Truck class="size-8 text-muted-foreground" />
            </Link>
        </div>
    </div>
</template>
