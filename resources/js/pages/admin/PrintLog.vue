<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import Pagination from '@/components/admin/Pagination.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import PrintPreview from '@/components/pos/PrintPreview.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import type { PrintDocument } from '@/pos/types';
import { log as logRoute } from '@/routes/admin/printing';

type Job = {
    id: number;
    kind: string;
    title: string | null;
    printerName: string | null;
    status: string;
    createdBy: string | null;
    createdAt: string | null;
    document: PrintDocument;
};

const props = defineProps<{
    jobs: { data: Job[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { kind?: string; printer?: number; date?: string };
    printers: { id: number; name: string }[];
}>();

const { t } = useI18n();
const selected = ref<Job | null>(props.jobs.data[0] ?? null);

const filters = reactive({
    kind: props.filters.kind ?? null,
    printer: props.filters.printer ? Number(props.filters.printer) : null,
    date: props.filters.date ?? '',
});

const kinds = ['order', 'ticket', 'bill', 'z_report', 'test', 'void', 'march', 'reprint', 'clock_qr'];

watch(filters, () => {
    router.get(
        logRoute.url(),
        { kind: filters.kind || undefined, printer: filters.printer || undefined, date: filters.date || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

watch(
    () => props.jobs.data,
    (jobs) => {
        if (!jobs.some((j) => j.id === selected.value?.id)) {
            selected.value = jobs[0] ?? null;
        }
    },
);

defineOptions({ layout: { breadcrumbs: [{ title: "Registre d'impressions", href: logRoute() }] } });
</script>

<template>
    <Head :title="t('admin.printing.log')" />
    <PageHeader :title="t('admin.printing.log')" :description="t('admin.printing.simulatedNotice')" />

    <div class="mb-4 flex flex-wrap gap-2">
        <SelectInput
            v-model="filters.kind"
            class="max-w-48"
            :placeholder="t('common.all')"
            :options="kinds.map((k) => ({ value: k as string | null, label: t(`admin.printing.kinds.${k}`) }))"
        />
        <SelectInput
            v-model="filters.printer"
            class="max-w-48"
            :placeholder="t('admin.printing.printers')"
            :options="printers.map((p) => ({ value: p.id as number | null, label: p.name }))"
        />
        <Input v-model="filters.date" type="date" class="max-w-44" />
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr_auto]">
        <div class="overflow-hidden rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th class="px-3 py-2">{{ t('common.date') }}</th>
                        <th class="px-3 py-2">{{ t('admin.printing.kind') }}</th>
                        <th class="px-3 py-2">{{ t('common.description') }}</th>
                        <th class="px-3 py-2">{{ t('admin.printing.printer') }}</th>
                        <th class="px-3 py-2">{{ t('common.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="job in jobs.data"
                        :key="job.id"
                        class="cursor-pointer border-t"
                        :class="selected?.id === job.id ? 'bg-primary/5' : 'hover:bg-muted/40'"
                        @click="selected = job"
                    >
                        <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(job.createdAt) }}</td>
                        <td class="px-3 py-2"><Badge variant="secondary">{{ t(`admin.printing.kinds.${job.kind}`) }}</Badge></td>
                        <td class="px-3 py-2">
                            {{ job.title }}
                            <div v-if="job.createdBy" class="text-xs text-muted-foreground">{{ job.createdBy }}</div>
                        </td>
                        <td class="px-3 py-2">{{ job.printerName ?? '—' }}</td>
                        <td class="px-3 py-2">{{ t(`admin.printing.statuses.${job.status}`) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="jobs.data.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
        </div>
        <div class="lg:sticky lg:top-4 lg:self-start">
            <div class="mb-2 text-sm font-medium">{{ t('admin.printing.preview') }}</div>
            <div class="rounded-xl bg-muted p-4">
                <PrintPreview v-if="selected" :document="selected.document" />
            </div>
        </div>
    </div>
    <Pagination :links="jobs.links" />
</template>
