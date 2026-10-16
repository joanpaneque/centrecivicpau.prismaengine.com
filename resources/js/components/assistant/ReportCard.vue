<script setup lang="ts">
import { BarChart3, Download, ExternalLink } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { ReportFormat, ReportPreview } from '@/assistant/api';
import { t } from '@/i18n';
import { formatMoney } from '@/pos/money';

/**
 * A business report in the chat: headline figures, one single-series chart (one hue, no
 * legend; tap or hover a bar to read it), the table with the exact values, and CSV.
 */
const props = defineProps<{ report: ReportPreview }>();

const ROWS_FOLDED = 8;
const showAll = ref(false);
const active = ref<number | null>(null);

const chart = computed(() => props.report.chart);
const max = computed(() => Math.max(1, ...(chart.value?.values.map((v) => Math.abs(v)) ?? [1])));
const peak = computed(() => (chart.value ? chart.value.values.indexOf(Math.max(...chart.value.values)) : -1));
const readout = computed(() => {
    if (!chart.value) {
        return null;
    }

    const index = active.value ?? peak.value;

    return { label: chart.value.labels[index] ?? '', value: format(chart.value.values[index] ?? 0, chart.value.format), isPeak: active.value === null };
});
const rows = computed(() => (showAll.value ? props.report.rows : props.report.rows.slice(0, ROWS_FOLDED)));
/** Columns with many bars only label every few so the axis stays readable. */
const labelEvery = computed(() => Math.max(1, Math.ceil((chart.value?.labels.length ?? 0) / 8)));

function format(value: number | string | null | undefined, kind: ReportFormat = 'text'): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'string') {
        return value;
    }

    switch (kind) {
        case 'eur':
            return formatMoney(value);
        case 'minutes':
            return `${Math.floor(value / 60)} h ${String(Math.abs(value) % 60).padStart(2, '0')} min`;
        case 'qty':
            return Number.isInteger(value) ? String(value) : value.toLocaleString(undefined, { maximumFractionDigits: 2 });
        case 'int':
            return value.toLocaleString();
        default:
            return String(value);
    }
}

function csvValue(value: string | number | null | undefined, kind?: ReportFormat): string {
    if (value === null || value === undefined) {
        return '';
    }

    const text = typeof value === 'number'
        ? kind === 'eur'
            ? (value / 100).toFixed(2).replace('.', ',')
            : kind === 'minutes'
              ? (value / 60).toFixed(2).replace('.', ',')
              : String(value).replace('.', ',')
        : value;

    return /[";\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function downloadCsv(): void {
    const header = props.report.columns.map((c) => csvValue(c.label)).join(';');
    const body = props.report.rows.map((row) => props.report.columns.map((c) => csvValue(row[c.key], c.format)).join(';'));
    const blob = new Blob([`﻿${[header, ...body].join('\n')}`], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${props.report.title.replace(/[^\p{L}\p{N}]+/gu, '-').toLowerCase()}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl border bg-white shadow-sm dark:bg-slate-900">
        <div class="flex items-start gap-3 border-b px-4 py-3">
            <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#00056a]/10 text-[#00056a] dark:bg-blue-300/15 dark:text-blue-200"><BarChart3 class="size-5" /></span>
            <div class="min-w-0 flex-1">
                <p class="font-bold">{{ report.title }}</p>
                <p class="text-xs text-slate-500">{{ report.subtitle }}</p>
            </div>
        </div>

        <!-- Headline figures -->
        <div v-if="report.kpis.length" class="grid grid-cols-2 gap-px bg-slate-100 sm:grid-cols-3 dark:bg-slate-800">
            <div v-for="kpi in report.kpis" :key="kpi.label" class="bg-white px-4 py-3 dark:bg-slate-900">
                <p class="text-xs text-slate-500">{{ kpi.label }}</p>
                <p class="text-xl font-bold tabular-nums" :class="kpi.tone === 'bad' ? 'text-red-700 dark:text-red-300' : kpi.tone === 'good' ? 'text-emerald-700 dark:text-emerald-300' : ''">
                    {{ format(kpi.value, kpi.format) }}
                </p>
                <p v-if="kpi.hint" class="truncate text-xs text-slate-500" :title="kpi.hint">{{ kpi.hint }}</p>
            </div>
        </div>

        <!-- One series: one hue, the title names it; the readout shows the bar under the finger -->
        <div v-if="chart" class="border-t px-4 pt-3 pb-2">
            <p v-if="readout" class="mb-2 flex items-baseline justify-between gap-2 text-sm">
                <span class="truncate text-slate-600 dark:text-slate-300">{{ readout.isPeak ? t('report.best') : '' }} {{ readout.label }}</span>
                <span class="shrink-0 font-semibold tabular-nums">{{ readout.value }}</span>
            </p>

            <div v-if="chart.kind === 'column'" class="flex h-36 items-end gap-0.5 border-b border-slate-300 dark:border-slate-600" @pointerleave="active = null">
                <button
                    v-for="(value, index) in chart.values"
                    :key="index"
                    type="button"
                    class="group flex h-full min-w-0 flex-1 items-end"
                    :aria-label="`${chart.labels[index]}: ${format(value, chart.format)}`"
                    @pointerenter="active = index"
                    @click="active = index"
                >
                    <span
                        class="w-full rounded-t-[4px] transition-colors"
                        :class="active === index ? 'bg-[#3b44c9] dark:bg-blue-200' : 'bg-[#00056a] dark:bg-blue-300'"
                        :style="{ height: `${Math.max(value > 0 ? 2 : 0, (Math.abs(value) / max) * 100)}%` }"
                    />
                </button>
            </div>
            <div v-if="chart.kind === 'column'" class="mt-1 flex gap-0.5 text-[10px] text-slate-500">
                <span v-for="(label, index) in chart.labels" :key="index" class="min-w-0 flex-1 truncate text-center">{{ index % labelEvery === 0 ? label : '' }}</span>
            </div>

            <div v-else class="space-y-1" @pointerleave="active = null">
                <button
                    v-for="(value, index) in chart.values.slice(0, 15)"
                    :key="index"
                    type="button"
                    class="grid w-full grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-2 text-left text-xs"
                    @pointerenter="active = index"
                    @click="active = index"
                >
                    <span class="truncate text-slate-600 dark:text-slate-300">{{ chart.labels[index] }}</span>
                    <span class="h-3.5">
                        <span
                            class="block h-full rounded-r-[4px] transition-colors"
                            :class="active === index ? 'bg-[#3b44c9] dark:bg-blue-200' : 'bg-[#00056a] dark:bg-blue-300'"
                            :style="{ width: `${Math.max(value > 0 ? 1 : 0, (Math.abs(value) / max) * 100)}%` }"
                        />
                    </span>
                    <span class="font-medium tabular-nums">{{ format(value, chart.format) }}</span>
                </button>
            </div>
        </div>

        <!-- Exact values -->
        <div v-if="report.rows.length" class="overflow-x-auto border-t">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-800/60">
                    <tr>
                        <th v-for="column in report.columns" :key="column.key" class="px-3 py-2 font-medium whitespace-nowrap" :class="column.align === 'right' ? 'text-right' : 'text-left'">{{ column.label }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(row, index) in rows" :key="index">
                        <td
                            v-for="column in report.columns"
                            :key="column.key"
                            class="px-3 py-2 align-top"
                            :class="[column.align === 'right' ? 'text-right tabular-nums whitespace-nowrap' : '', column.key === report.columns[0].key ? 'font-medium' : '']"
                        >
                            <a v-if="column.link && row[column.link]" :href="String(row[column.link])" target="_blank" rel="noopener" class="text-[#00056a] underline dark:text-blue-300">{{ format(row[column.key], column.format) }}</a>
                            <template v-else>{{ format(row[column.key], column.format) }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <button v-if="report.rows.length > ROWS_FOLDED" type="button" class="w-full border-t py-2.5 text-sm font-semibold text-[#00056a] dark:text-blue-300" @click="showAll = !showAll">
                {{ showAll ? t('report.showLess') : t('report.showAll', { count: report.rows.length }) }}
            </button>
        </div>
        <p v-else class="border-t px-4 py-4 text-center text-sm text-slate-500">{{ t('report.empty') }}</p>

        <div class="flex flex-wrap gap-2 border-t bg-slate-50 p-2 dark:bg-slate-800/50">
            <button v-if="report.rows.length" type="button" class="flex h-11 flex-1 items-center justify-center gap-2 rounded-xl border bg-white px-3 text-sm font-semibold text-slate-700 active:scale-[0.98] dark:bg-slate-900 dark:text-slate-100" @click="downloadCsv">
                <Download class="size-4" /> CSV
            </button>
            <a
                v-for="link in report.links"
                :key="link.url"
                :href="link.url"
                target="_blank"
                rel="noopener"
                class="flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-[#00056a] px-3 text-sm font-semibold text-white active:scale-[0.98]"
            >
                <ExternalLink class="size-4" /> {{ link.label }}
            </a>
        </div>
    </div>
</template>
