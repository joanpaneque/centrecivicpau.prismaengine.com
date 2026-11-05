<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n';
import { traffic as trafficRoute } from '@/routes/admin';

/**
 * Afluència: orders opened by weekday and hour, as an average per day the place was open,
 * to see the peak hours, the quiet ones and the patterns in between.
 */
type Cell = { weekday: number; hour: number; orders: number; guests: number };
type Metric = 'orders' | 'guests';

const props = defineProps<{
    days: number;
    periods: number[];
    from: string;
    total: number;
    cells: Cell[];
    openDays: Record<string, number>;
}>();

const { t, locale } = useI18n();

const metric = ref<Metric>('orders');
const showTable = ref(false);
/** The slot under the finger or the pointer (read out above the map). */
const active = ref<{ weekday: number; hour: number } | null>(null);

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
const intl = computed(() => (locale.value === 'es' ? 'es-ES' : 'ca-ES'));
// 1 January 2024 was a Monday.
const dayName = (weekday: number, style: 'short' | 'long' = 'long') => new Date(2024, 0, weekday).toLocaleDateString(intl.value, { weekday: style });
const hourLabel = (hour: number) => `${String(hour).padStart(2, '0')}:00`;
const number = (value: number) => value.toLocaleString(intl.value, { maximumFractionDigits: 1 });

const openDays = (weekday: number) => props.openDays[String(weekday)] ?? 0;
const totalOpenDays = computed(() => WEEKDAYS.reduce((sum, weekday) => sum + openDays(weekday), 0));

const counts = computed(() => {
    const map = new Map<string, number>();

    for (const cell of props.cells) {
        map.set(`${cell.weekday}-${cell.hour}`, metric.value === 'orders' ? cell.orders : cell.guests);
    }

    return map;
});

/** Average per open day of that weekday. */
function average(weekday: number, hour: number): number {
    const days = openDays(weekday);

    return days ? (counts.value.get(`${weekday}-${hour}`) ?? 0) / days : 0;
}

/** Only the hours with some activity, from the first to the last. */
const hours = computed(() => {
    const used = props.cells.map((cell) => cell.hour);

    if (!used.length) {
        return [];
    }

    const first = Math.min(...used);
    const last = Math.max(...used);

    return Array.from({ length: last - first + 1 }, (_, i) => first + i);
});

const slots = computed(() => WEEKDAYS.flatMap((weekday) => hours.value.map((hour) => ({ weekday, hour, value: average(weekday, hour) }))));
const maxSlot = computed(() => Math.max(0, ...slots.value.map((slot) => slot.value)));

/** Sequential scale, 7 steps: lighter = quieter (darker in light mode is busier). */
function level(value: number): number {
    if (value <= 0 || !maxSlot.value) {
        return -1;
    }

    return Math.min(6, Math.floor((value / maxSlot.value) * 7));
}

const hourly = computed(() =>
    hours.value.map((hour) => {
        const sum = WEEKDAYS.reduce((acc, weekday) => acc + (counts.value.get(`${weekday}-${hour}`) ?? 0), 0);

        return { hour, value: totalOpenDays.value ? sum / totalOpenDays.value : 0 };
    }),
);
const maxHourly = computed(() => Math.max(0, ...hourly.value.map((row) => row.value)));
const peakHour = computed(() => hourly.value.reduce<{ hour: number; value: number } | null>((best, row) => (!best || row.value > best.value ? row : best), null));

const daily = computed(() =>
    WEEKDAYS.filter((weekday) => openDays(weekday) > 0).map((weekday) => {
        const sum = hours.value.reduce((acc, hour) => acc + (counts.value.get(`${weekday}-${hour}`) ?? 0), 0);

        return { weekday, value: sum / openDays(weekday) };
    }),
);
const busiestDay = computed(() => [...daily.value].sort((a, b) => b.value - a.value)[0] ?? null);
const quietestDay = computed(() => [...daily.value].sort((a, b) => a.value - b.value)[0] ?? null);
const perOpenDay = computed(() => (totalOpenDays.value ? daily.value.reduce((sum, row) => sum + row.value * openDays(row.weekday), 0) / totalOpenDays.value : 0));

/** Hours the place is usually open on that weekday: between its first and last busy hour. */
function openSlots(): { weekday: number; hour: number; value: number }[] {
    return WEEKDAYS.flatMap((weekday) => {
        const busy = hours.value.filter((hour) => average(weekday, hour) > 0);

        if (!busy.length) {
            return [];
        }

        const first = busy[0];
        const last = busy[busy.length - 1];

        return hours.value.filter((hour) => hour >= first && hour <= last).map((hour) => ({ weekday, hour, value: average(weekday, hour) }));
    });
}

const quietSlots = computed(() => [...openSlots()].sort((a, b) => a.value - b.value).slice(0, 4));
const busySlots = computed(() => [...slots.value].filter((slot) => slot.value > 0).sort((a, b) => b.value - a.value).slice(0, 4));

const unit = computed(() => (metric.value === 'orders' ? t('traffic.ordersUnit') : t('traffic.guestsUnit')));
const activeText = computed(() => {
    if (!active.value) {
        return t('traffic.tapHelp');
    }

    const { weekday, hour } = active.value;

    return `${dayName(weekday)} ${hourLabel(hour)}–${hourLabel(hour + 1)} · ${number(average(weekday, hour))} ${unit.value} ${t('traffic.perDay')}`;
});

function setPeriod(days: number): void {
    router.get(trafficRoute.url({ query: { dies: days } }), {}, { preserveScroll: true, preserveState: true });
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Afluència', href: trafficRoute() }] } });
</script>

<template>
    <Head :title="t('traffic.title')" />
    <PageHeader :title="t('traffic.title')" :description="t('traffic.description')" />

    <div class="traffic-viz space-y-5">
        <!-- Filters: one row above the charts -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border p-0.5">
                <button
                    v-for="period in periods"
                    :key="period"
                    type="button"
                    class="h-9 rounded-md px-3 text-sm font-medium"
                    :class="period === days ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'"
                    @click="setPeriod(period)"
                >
                    {{ t('traffic.lastDays', { count: period }) }}
                </button>
            </div>
            <div class="inline-flex rounded-lg border p-0.5">
                <button
                    v-for="m in ['orders', 'guests'] as const"
                    :key="m"
                    type="button"
                    class="h-9 rounded-md px-3 text-sm font-medium"
                    :class="metric === m ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'"
                    @click="metric = m"
                >
                    {{ t(`traffic.metric.${m}`) }}
                </button>
            </div>
            <Button variant="outline" size="sm" class="ml-auto h-9" @click="showTable = !showTable">{{ showTable ? t('traffic.showCharts') : t('traffic.showTable') }}</Button>
        </div>

        <p v-if="!total" class="rounded-xl border py-12 text-center text-sm text-muted-foreground">{{ t('traffic.empty') }}</p>

        <template v-else>
            <!-- Headline numbers -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl border p-3">
                    <p class="text-xs text-muted-foreground">{{ t('traffic.peakHour') }}</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ peakHour ? hourLabel(peakHour.hour) : '—' }}</p>
                    <p class="text-xs text-muted-foreground">{{ peakHour ? `${number(peakHour.value)} ${unit} ${t('traffic.perDay')}` : '' }}</p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-xs text-muted-foreground">{{ t('traffic.busiestDay') }}</p>
                    <p class="text-2xl font-semibold capitalize">{{ busiestDay ? dayName(busiestDay.weekday) : '—' }}</p>
                    <p class="text-xs text-muted-foreground">{{ busiestDay ? `${number(busiestDay.value)} ${unit} ${t('traffic.perDay')}` : '' }}</p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-xs text-muted-foreground">{{ t('traffic.quietestDay') }}</p>
                    <p class="text-2xl font-semibold capitalize">{{ quietestDay ? dayName(quietestDay.weekday) : '—' }}</p>
                    <p class="text-xs text-muted-foreground">{{ quietestDay ? `${number(quietestDay.value)} ${unit} ${t('traffic.perDay')}` : '' }}</p>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-xs text-muted-foreground">{{ t('traffic.average') }}</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ number(perOpenDay) }}</p>
                    <p class="text-xs text-muted-foreground">{{ t('traffic.averageHelp', { unit, days: totalOpenDays }) }}</p>
                </div>
            </div>

            <!-- Table view: every number of the map -->
            <div v-if="showTable" class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <caption class="px-3 py-2 text-left text-xs text-muted-foreground">{{ t('traffic.tableCaption', { unit }) }}</caption>
                    <thead class="bg-muted/40 text-xs text-muted-foreground">
                        <tr>
                            <th class="px-2 py-2 text-left">{{ t('traffic.hour') }}</th>
                            <th v-for="weekday in WEEKDAYS" :key="weekday" class="px-2 py-2 text-right capitalize">{{ dayName(weekday, 'short') }}</th>
                            <th class="px-2 py-2 text-right">{{ t('traffic.all') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in hourly" :key="row.hour" class="border-t">
                            <td class="px-2 py-1.5 tabular-nums">{{ hourLabel(row.hour) }}</td>
                            <td v-for="weekday in WEEKDAYS" :key="weekday" class="px-2 py-1.5 text-right tabular-nums">{{ average(weekday, row.hour) ? number(average(weekday, row.hour)) : '·' }}</td>
                            <td class="px-2 py-1.5 text-right font-medium tabular-nums">{{ number(hourly[index].value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template v-else>
                <!-- Heatmap: hours down, weekdays across, so it fits a phone -->
                <section class="rounded-xl border p-3 sm:p-4">
                    <h2 class="font-semibold">{{ t('traffic.mapTitle') }}</h2>
                    <p class="mt-0.5 min-h-5 text-sm text-muted-foreground" aria-live="polite">{{ activeText }}</p>

                    <div class="mt-3 grid gap-[2px]" style="grid-template-columns: 3rem repeat(7, minmax(0, 1fr))" @mouseleave="active = null">
                        <span />
                        <span v-for="weekday in WEEKDAYS" :key="weekday" class="pb-1 text-center text-xs font-medium text-muted-foreground capitalize">{{ dayName(weekday, 'short') }}</span>
                        <template v-for="hour in hours" :key="hour">
                            <span class="flex items-center text-xs text-muted-foreground tabular-nums">{{ hourLabel(hour) }}</span>
                            <button
                                v-for="weekday in WEEKDAYS"
                                :key="weekday"
                                type="button"
                                class="h-7 rounded-[4px] outline-offset-1 sm:h-8"
                                :class="active?.weekday === weekday && active?.hour === hour ? 'outline-2 outline-[var(--text-primary)]' : ''"
                                :style="{ background: `var(--heat-${level(average(weekday, hour)) < 0 ? 'none' : level(average(weekday, hour))})` }"
                                :aria-label="`${dayName(weekday)} ${hourLabel(hour)}: ${number(average(weekday, hour))} ${unit}`"
                                @mouseenter="active = { weekday, hour }"
                                @focus="active = { weekday, hour }"
                                @click="active = { weekday, hour }"
                            />
                        </template>
                    </div>

                    <div class="mt-3 flex items-center gap-2 text-xs text-muted-foreground">
                        <span>{{ t('traffic.less') }}</span>
                        <span class="flex gap-[2px]">
                            <span class="size-3.5 rounded-[3px]" style="background: var(--heat-none)" />
                            <span v-for="step in 7" :key="step" class="size-3.5 rounded-[3px]" :style="{ background: `var(--heat-${step - 1})` }" />
                        </span>
                        <span>{{ t('traffic.more') }}</span>
                        <span class="ml-auto">{{ t('traffic.max', { value: number(maxSlot), unit }) }}</span>
                    </div>
                </section>

                <!-- Average by hour, every open day together -->
                <section class="rounded-xl border p-3 sm:p-4">
                    <h2 class="font-semibold">{{ t('traffic.byHourTitle') }}</h2>
                    <p class="text-sm text-muted-foreground">{{ t('traffic.byHourHelp', { unit }) }}</p>
                    <div class="mt-4 flex h-44 items-end gap-[2px] border-b border-[var(--grid)]">
                        <div v-for="row in hourly" :key="row.hour" class="group relative flex h-full flex-1 flex-col justify-end" :title="`${hourLabel(row.hour)}: ${number(row.value)} ${unit}`">
                            <span v-if="peakHour && row.hour === peakHour.hour" class="mb-1 text-center text-xs font-semibold tabular-nums">{{ number(row.value) }}</span>
                            <div class="rounded-t-[4px] bg-[var(--bar)] group-hover:opacity-80" :style="{ height: `${maxHourly ? (row.value / maxHourly) * 100 : 0}%`, minHeight: row.value > 0 ? '2px' : '0' }" />
                        </div>
                    </div>
                    <div class="mt-1 flex gap-[2px]">
                        <span v-for="(row, index) in hourly" :key="row.hour" class="flex-1 text-center text-[10px] text-muted-foreground tabular-nums">{{ index % 2 === 0 ? row.hour : '' }}</span>
                    </div>
                </section>

                <!-- Patterns -->
                <section class="grid gap-3 md:grid-cols-2">
                    <div class="rounded-xl border p-3 sm:p-4">
                        <h2 class="font-semibold">{{ t('traffic.quietTitle') }}</h2>
                        <p class="text-sm text-muted-foreground">{{ t('traffic.quietHelp') }}</p>
                        <ul class="mt-2 divide-y text-sm">
                            <li v-for="slot in quietSlots" :key="`${slot.weekday}-${slot.hour}`" class="flex justify-between py-1.5">
                                <span class="capitalize">{{ dayName(slot.weekday) }} {{ hourLabel(slot.hour) }}</span>
                                <span class="text-muted-foreground tabular-nums">{{ number(slot.value) }} {{ unit }}</span>
                            </li>
                        </ul>
                    </div>
                    <div class="rounded-xl border p-3 sm:p-4">
                        <h2 class="font-semibold">{{ t('traffic.busyTitle') }}</h2>
                        <p class="text-sm text-muted-foreground">{{ t('traffic.busyHelp') }}</p>
                        <ul class="mt-2 divide-y text-sm">
                            <li v-for="slot in busySlots" :key="`${slot.weekday}-${slot.hour}`" class="flex justify-between py-1.5">
                                <span class="capitalize">{{ dayName(slot.weekday) }} {{ hourLabel(slot.hour) }}</span>
                                <span class="font-medium tabular-nums">{{ number(slot.value) }} {{ unit }}</span>
                            </li>
                        </ul>
                    </div>
                </section>
            </template>

            <p class="text-xs text-muted-foreground">{{ t('traffic.footnote', { from: new Date(from).toLocaleDateString(intl), total }) }}</p>
        </template>
    </div>
</template>

<style>
/* Sequential blue (one hue), lighter = quieter. The back office is always light (useAppearance). */
.traffic-viz {
    --text-primary: #0b0b0b;
    --grid: #e4e3df;
    --bar: #2a78d6;
    --heat-none: #f1f0ed;
    --heat-0: #cde2fb;
    --heat-1: #9ec5f4;
    --heat-2: #6da7ec;
    --heat-3: #3987e5;
    --heat-4: #2a78d6;
    --heat-5: #1c5cab;
    --heat-6: #104281;
}
</style>
