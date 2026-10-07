<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Ban, Clock, Pencil, Plus, ShieldCheck, ShieldX } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import TextArea from '@/components/admin/TextArea.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDate, formatDateTime, formatMinutes, formatTime, toLocalInput } from '@/lib/format';
import { time as timeRoute } from '@/routes/admin';
import { correct, exportMethod } from '@/routes/admin/time';

type EntryType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';
type Totals = { worked: number; planned: number; overtime: number; breaks: number };
type Correction = { id: number; action: 'modify' | 'add' | 'annul'; newType: EntryType | null; newOccurredAt: string | null; reason: string; by: string; createdAt: string };
type Entry = {
    id: number;
    type: EntryType;
    occurredAt: string;
    receivedAt: string;
    source: string;
    device: string | null;
    syncedLate: boolean;
    hash: string;
    corrections: Correction[];
};
type Day = {
    date: string;
    sessions: { start: string; end: string | null; break_minutes: number; worked_minutes: number }[];
    worked_minutes: number;
    break_minutes: number;
    planned_minutes: number;
    overtime_minutes: number;
    shifts: string[];
};

const props = defineProps<{
    workers: { id: number; name: string; active: boolean }[];
    summary: { id: number; name: string; totals: Totals; alerts: number }[];
    detail: {
        user: { id: number; name: string; taxId: string | null };
        report: { days: Day[]; totals: Totals; alerts: { type: string; date: string; at: string }[] };
        entries: Entry[];
        added: Omit<Correction, 'action'>[];
        chain: { valid: boolean; checked: number; broken_at: number | null };
    } | null;
    filters: { from: string; to: string; user: number | null };
}>();

const { t } = useI18n();

const typeLabels: Record<EntryType, string> = { clock_in: 'clock.clockIn', clock_out: 'clock.clockOut', break_start: 'clock.breakStart', break_end: 'clock.breakEnd' };
const typeOptions = computed(() => (Object.keys(typeLabels) as EntryType[]).map((value) => ({ value, label: t(typeLabels[value]) })));

const filters = reactive({ from: props.filters.from, to: props.filters.to, user: props.filters.user });

watch(filters, () => {
    router.get(timeRoute.url(), { from: filters.from, to: filters.to, user: filters.user || undefined }, { preserveState: true, preserveScroll: true, replace: true });
});

function exportUrl(format: 'pdf' | 'csv' | 'xlsx'): string {
    return exportMethod.url({ query: { format, from: filters.from, to: filters.to, user: filters.user || undefined } });
}

const correctionOpen = ref(false);
const correctionEntry = ref<Entry | null>(null);
const form = useForm({
    action: 'modify' as 'modify' | 'add' | 'annul',
    time_entry_id: null as number | null,
    new_type: null as EntryType | null,
    new_occurred_at: '',
    reason: '',
});

function openCorrection(action: 'modify' | 'add' | 'annul', entry: Entry | null = null): void {
    correctionEntry.value = entry;
    form.clearErrors();
    form.action = action;
    form.time_entry_id = entry?.id ?? null;
    form.new_type = action === 'add' ? 'clock_out' : null;
    form.new_occurred_at = entry ? toLocalInput(entry.occurredAt) : '';
    form.reason = '';
    correctionOpen.value = true;
}

function submitCorrection(): void {
    if (!props.detail) {
        return;
    }

    form.transform((data) => ({
        ...data,
        new_occurred_at: data.action === 'annul' || !data.new_occurred_at ? null : new Date(data.new_occurred_at).toISOString(),
    })).post(correct.url(props.detail.user.id), { preserveScroll: true, onSuccess: () => (correctionOpen.value = false) });
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Registre horari', href: timeRoute() }] } });
</script>

<template>
    <Head :title="t('admin.time.title')" />
    <PageHeader :title="t('admin.time.title')" :description="t('admin.time.description')">
        <Button variant="outline" size="sm" as-child><a :href="exportUrl('pdf')">{{ t('admin.time.exportPdf') }}</a></Button>
        <Button variant="outline" size="sm" as-child><a :href="exportUrl('csv')">{{ t('admin.time.exportCsv') }}</a></Button>
        <Button variant="outline" size="sm" as-child><a :href="exportUrl('xlsx')">{{ t('admin.time.exportXlsx') }}</a></Button>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-end gap-2">
        <Field :label="t('common.from')"><Input v-model="filters.from" type="date" class="w-40" /></Field>
        <Field :label="t('common.to')"><Input v-model="filters.to" type="date" class="w-40" /></Field>
        <Field :label="t('admin.time.worker')">
            <SelectInput
                v-model="filters.user"
                class="w-56"
                :placeholder="t('admin.time.allWorkers')"
                :options="workers.map((w) => ({ value: w.id as number | null, label: w.active ? w.name : `${w.name} (${t('common.inactive').toLowerCase()})` }))"
            />
        </Field>
    </div>

    <p class="mb-4 rounded-lg border border-dashed bg-muted/30 p-3 text-xs text-muted-foreground">{{ t('admin.time.immutable') }}</p>

    <div v-if="!detail" class="overflow-x-auto rounded-xl border">
        <table class="w-full text-left text-sm">
            <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                <tr>
                    <th class="px-3 py-2">{{ t('admin.time.worker') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('admin.time.worked') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('admin.time.planned') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('admin.time.balance') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('admin.time.breaks') }}</th>
                    <th class="px-3 py-2" />
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in summary" :key="row.id" class="cursor-pointer border-t hover:bg-muted/30" @click="filters.user = row.id">
                    <td class="px-3 py-2 font-medium">{{ row.name }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ formatMinutes(row.totals.worked) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ formatMinutes(row.totals.planned) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums" :class="row.totals.worked - row.totals.planned > 0 ? 'text-amber-600' : ''">
                        {{ row.totals.planned ? formatMinutes(row.totals.worked - row.totals.planned) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ formatMinutes(row.totals.breaks) }}</td>
                    <td class="px-3 py-2 text-right">
                        <Badge v-if="row.alerts" variant="destructive"><AlertTriangle class="size-3" /> {{ row.alerts }}</Badge>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-else class="space-y-6">
        <div class="flex flex-wrap items-center gap-3">
            <Button variant="ghost" size="sm" @click="filters.user = null"><ArrowLeft class="size-4" /> {{ t('common.back') }}</Button>
            <h2 class="text-lg font-semibold">{{ detail.user.name }}</h2>
            <span v-if="detail.user.taxId" class="text-sm text-muted-foreground">{{ detail.user.taxId }}</span>
            <Badge :variant="detail.chain.valid ? 'secondary' : 'destructive'" class="gap-1">
                <component :is="detail.chain.valid ? ShieldCheck : ShieldX" class="size-3.5" />
                {{ detail.chain.valid ? t('admin.time.chainOk') : t('admin.time.chainBroken') }} ({{ detail.chain.checked }})
            </Badge>
            <Button size="sm" class="ml-auto" @click="openCorrection('add')"><Plus class="size-4" /> {{ t('admin.time.addEntry') }}</Button>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-3"><div class="text-xs text-muted-foreground">{{ t('admin.time.worked') }}</div><div class="text-xl font-semibold tabular-nums">{{ formatMinutes(detail.report.totals.worked) }}</div></div>
            <div class="rounded-xl border p-3"><div class="text-xs text-muted-foreground">{{ t('admin.time.planned') }}</div><div class="text-xl font-semibold tabular-nums">{{ formatMinutes(detail.report.totals.planned) }}</div></div>
            <div class="rounded-xl border p-3"><div class="text-xs text-muted-foreground">{{ t('admin.time.balance') }}</div><div class="text-xl font-semibold tabular-nums">{{ formatMinutes(detail.report.totals.overtime) }}</div></div>
            <div class="rounded-xl border p-3"><div class="text-xs text-muted-foreground">{{ t('admin.time.breaks') }}</div><div class="text-xl font-semibold tabular-nums">{{ formatMinutes(detail.report.totals.breaks) }}</div></div>
        </div>

        <div v-if="detail.report.alerts.length" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
            <div v-for="(alert, index) in detail.report.alerts" :key="index" class="flex items-center gap-2">
                <AlertTriangle class="size-4 text-amber-600" />
                {{ formatDate(alert.date) }} {{ formatTime(alert.at) }} ·
                {{ alert.type === 'missing_clock_out' ? t('admin.time.missingClockOut') : t('admin.time.offShift') }}
            </div>
        </div>

        <section>
            <h3 class="mb-2 font-medium">{{ t('admin.time.days') }}</h3>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                        <tr>
                            <th class="px-3 py-2">{{ t('common.date') }}</th>
                            <th class="px-3 py-2">{{ t('nav.shifts') }}</th>
                            <th class="px-3 py-2">{{ t('admin.time.entries') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('admin.time.breaks') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('admin.time.worked') }}</th>
                            <th class="px-3 py-2 text-right">{{ t('admin.time.planned') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="day in detail.report.days" :key="day.date" class="border-t">
                            <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(day.date, { weekday: 'short', day: '2-digit', month: '2-digit' }) }}</td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">{{ day.shifts.join(', ') || '—' }}</td>
                            <td class="px-3 py-2">
                                <span v-for="(session, index) in day.sessions" :key="index" class="mr-2 whitespace-nowrap">
                                    {{ formatTime(session.start) }}–{{ session.end ? formatTime(session.end) : '…' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatMinutes(day.break_minutes) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ formatMinutes(day.worked_minutes) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ formatMinutes(day.planned_minutes) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h3 class="mb-2 font-medium">{{ t('admin.time.entries') }}</h3>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                        <tr>
                            <th class="px-3 py-2">{{ t('common.date') }}</th>
                            <th class="px-3 py-2">{{ t('admin.time.type') }}</th>
                            <th class="px-3 py-2">{{ t('admin.time.source') }}</th>
                            <th class="px-3 py-2">{{ t('admin.time.receivedAt') }}</th>
                            <th class="px-3 py-2">{{ t('admin.time.corrections') }}</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in detail.entries" :key="entry.id" class="border-t align-top">
                            <td class="px-3 py-2 whitespace-nowrap" :class="{ 'line-through opacity-60': entry.corrections.length }">
                                {{ formatDateTime(entry.occurredAt) }}
                            </td>
                            <td class="px-3 py-2">{{ t(typeLabels[entry.type]) }}</td>
                            <td class="px-3 py-2 text-xs">
                                {{ entry.source }}<span v-if="entry.device"> · {{ entry.device }}</span>
                                <Badge v-if="entry.syncedLate" variant="outline" class="ml-1">{{ t('admin.time.syncedLate') }}</Badge>
                            </td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground">{{ formatDateTime(entry.receivedAt) }}</td>
                            <td class="px-3 py-2 text-xs">
                                <div v-for="c in entry.corrections" :key="c.id" class="mb-1">
                                    <Badge :variant="c.action === 'annul' ? 'destructive' : 'secondary'">{{ t(`admin.time.correctionTypes.${c.action}`) }}</Badge>
                                    <span v-if="c.newOccurredAt"> → {{ formatDateTime(c.newOccurredAt) }}</span>
                                    <div class="text-muted-foreground">«{{ c.reason }}» · {{ c.by }}, {{ formatDateTime(c.createdAt) }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <Button variant="ghost" size="icon" :title="t('admin.time.modify')" @click="openCorrection('modify', entry)"><Pencil class="size-4" /></Button>
                                <Button variant="ghost" size="icon" :title="t('admin.time.annul')" @click="openCorrection('annul', entry)"><Ban class="size-4" /></Button>
                            </td>
                        </tr>
                        <tr v-for="added in detail.added" :key="`a${added.id}`" class="border-t bg-emerald-50/50 dark:bg-emerald-950/20">
                            <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(added.newOccurredAt) }}</td>
                            <td class="px-3 py-2">{{ added.newType ? t(typeLabels[added.newType]) : '' }}</td>
                            <td class="px-3 py-2 text-xs"><Badge>{{ t('admin.time.correctionTypes.add') }}</Badge></td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">{{ formatDateTime(added.createdAt) }}</td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">«{{ added.reason }}» · {{ added.by }}</td>
                            <td />
                        </tr>
                    </tbody>
                </table>
                <p v-if="detail.entries.length === 0 && detail.added.length === 0" class="py-8 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
            </div>
        </section>
    </div>

    <Dialog v-model:open="correctionOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="submitCorrection">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Clock class="size-5" />
                        {{ form.action === 'add' ? t('admin.time.addEntry') : form.action === 'annul' ? t('admin.time.annul') : t('admin.time.modify') }}
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="correctionEntry">
                            {{ t(typeLabels[correctionEntry.type]) }} · {{ formatDateTime(correctionEntry.occurredAt) }}
                        </template>
                        <template v-else>{{ detail?.user.name }}</template>
                    </DialogDescription>
                </DialogHeader>
                <Field v-if="form.action !== 'annul'" :label="t('admin.time.type')" :error="form.errors.new_type">
                    <SelectInput v-model="form.new_type" :placeholder="form.action === 'modify' ? '—' : undefined" :options="typeOptions" />
                </Field>
                <Field v-if="form.action !== 'annul'" :label="t('admin.time.newTime')" :error="form.errors.new_occurred_at">
                    <Input v-model="form.new_occurred_at" type="datetime-local" required />
                </Field>
                <Field :label="t('common.reason')" :error="form.errors.reason || form.errors.time_entry_id">
                    <TextArea v-model="form.reason" :rows="3" />
                </Field>
                <p class="text-xs text-muted-foreground">{{ t('clock.legal') }}</p>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing || form.reason.length < 5">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
