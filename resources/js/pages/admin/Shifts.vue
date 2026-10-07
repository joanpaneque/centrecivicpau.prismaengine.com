<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Copy, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { tl, useI18n } from '@/i18n';
import { addDays, formatDate, formatMinutes, todayIso } from '@/lib/format';
import { shifts as shiftsRoute } from '@/routes/admin';
import * as templateRoutes from '@/routes/admin/shift-templates';
import { copyWeek, destroy, store, update } from '@/routes/admin/shifts';

type Worker = { id: number; name: string; color: string | null };
type Template = { id: number; name: string; startTime: string; endTime: string; breakMinutes: number; color: string | null };
type Shift = { id: number; userId: number; date: string; startTime: string; endTime: string; breakMinutes: number; templateId: number | null; notes: string | null; minutes: number };

const props = defineProps<{
    view: 'week' | 'month';
    anchor: string;
    from: string;
    to: string;
    workers: Worker[];
    templates: Template[];
    shifts: Shift[];
}>();

const { t } = useI18n();

const days = computed(() => {
    const list: string[] = [];

    for (let day = props.from; day <= props.to; day = addDays(day, 1)) {
        list.push(day);
    }

    return list;
});

const byCell = computed(() => {
    const map = new Map<string, Shift[]>();

    for (const shift of props.shifts) {
        const key = `${shift.userId}|${shift.date}`;
        map.set(key, [...(map.get(key) ?? []), shift]);
    }

    return map;
});

const byDay = computed(() => {
    const map = new Map<string, Shift[]>();

    for (const shift of props.shifts) {
        map.set(shift.date, [...(map.get(shift.date) ?? []), shift]);
    }

    return map;
});

function workerMinutes(userId: number): number {
    return props.shifts.filter((s) => s.userId === userId).reduce((sum, s) => sum + s.minutes, 0);
}

function workerName(userId: number): string {
    return props.workers.find((w) => w.id === userId)?.name ?? '?';
}

function shiftColor(shift: Shift): string {
    return props.templates.find((tpl) => tpl.id === shift.templateId)?.color ?? props.workers.find((w) => w.id === shift.userId)?.color ?? '#00056a';
}

function navigate(params: { date?: string; view?: 'week' | 'month' }): void {
    router.get(shiftsRoute.url(), { date: params.date ?? props.anchor, view: params.view ?? props.view }, { preserveScroll: true, preserveState: true });
}

function shiftPeriod(direction: number): void {
    if (props.view === 'week') {
        navigate({ date: addDays(props.anchor, direction * 7) });
    } else {
        const date = new Date(`${props.anchor}T00:00:00`);
        date.setMonth(date.getMonth() + direction, 1);
        navigate({ date: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-01` });
    }
}

function copyPreviousWeek(): void {
    router.post(copyWeek.url(), { date: props.from }, { preserveScroll: true });
}

type DragData = { kind: 'shift'; id: number } | { kind: 'template'; id: number };
const dragOver = ref<string | null>(null);

function dragStart(event: DragEvent, data: DragData): void {
    event.dataTransfer?.setData('application/json', JSON.stringify(data));

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copyMove';
    }
}

function drop(event: DragEvent, date: string, userId: number | null): void {
    dragOver.value = null;
    const raw = event.dataTransfer?.getData('application/json');

    if (!raw) {
        return;
    }

    const data = JSON.parse(raw) as DragData;

    if (data.kind === 'template') {
        if (userId === null) {
            openCreate(date, null, data.id);

            return;
        }

        router.post(store.url(), { userId, date, templateId: data.id }, { preserveScroll: true, preserveState: true });

        return;
    }

    const shift = props.shifts.find((s) => s.id === data.id);

    if (!shift) {
        return;
    }

    const copy = event.ctrlKey || event.altKey || event.metaKey;
    router.patch(update.url(shift.id), { userId: userId ?? shift.userId, date, copy }, { preserveScroll: true, preserveState: true });
}

const shiftOpen = ref(false);
const editing = ref<Shift | null>(null);
const form = useForm({
    userId: null as number | null,
    date: '',
    templateId: null as number | null,
    startTime: '09:00',
    endTime: '17:00',
    breakMinutes: 0,
    notes: '',
});

function applyTemplate(id: number | null | undefined): void {
    const template = props.templates.find((tpl) => tpl.id === id);

    if (template) {
        form.startTime = template.startTime;
        form.endTime = template.endTime;
        form.breakMinutes = template.breakMinutes;
    }
}

function openCreate(date: string, userId: number | null, templateId: number | null = null): void {
    editing.value = null;
    form.clearErrors();
    form.userId = userId;
    form.date = date;
    form.templateId = templateId ?? props.templates[0]?.id ?? null;
    form.notes = '';
    form.breakMinutes = 0;
    applyTemplate(form.templateId);
    shiftOpen.value = true;
}

function openEdit(shift: Shift): void {
    editing.value = shift;
    form.clearErrors();
    form.userId = shift.userId;
    form.date = shift.date;
    form.templateId = shift.templateId;
    form.startTime = shift.startTime;
    form.endTime = shift.endTime;
    form.breakMinutes = shift.breakMinutes;
    form.notes = shift.notes ?? '';
    shiftOpen.value = true;
}

function saveShift(): void {
    form.transform((data) => ({ ...data, notes: data.notes || null }));
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => (shiftOpen.value = false) };

    if (editing.value) {
        form.patch(update.url(editing.value.id), options);
    } else {
        form.post(store.url(), options);
    }
}

function removeShift(): void {
    if (editing.value) {
        router.delete(destroy.url(editing.value.id), { preserveScroll: true, preserveState: true, onSuccess: () => (shiftOpen.value = false) });
    }
}

const templateOpen = ref(false);
const editingTemplate = ref<Template | null>(null);
const templateForm = useForm({ name: '', startTime: '09:00', endTime: '17:00', breakMinutes: 0, color: '#00056a' });

function openTemplate(template: Template | null): void {
    editingTemplate.value = template;
    templateForm.clearErrors();
    templateForm.name = template?.name ?? '';
    templateForm.startTime = template?.startTime ?? '09:00';
    templateForm.endTime = template?.endTime ?? '17:00';
    templateForm.breakMinutes = template?.breakMinutes ?? 0;
    templateForm.color = template?.color ?? '#00056a';
    templateOpen.value = true;
}

function saveTemplate(): void {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => (templateOpen.value = false) };

    if (editingTemplate.value) {
        templateForm.patch(templateRoutes.update.url(editingTemplate.value.id), options);
    } else {
        templateForm.post(templateRoutes.store.url(), options);
    }
}

function removeTemplate(): void {
    if (editingTemplate.value) {
        router.delete(templateRoutes.destroy.url(editingTemplate.value.id), { preserveScroll: true, preserveState: true, onSuccess: () => (templateOpen.value = false) });
    }
}

const title = computed(() =>
    props.view === 'week'
        ? `${formatDate(props.from, { day: 'numeric', month: 'short' })} – ${formatDate(props.to, { day: 'numeric', month: 'short', year: 'numeric' })}`
        : formatDate(props.anchor, { month: 'long', year: 'numeric' }),
);

const anchorMonth = computed(() => props.anchor.slice(0, 7));

defineOptions({ layout: { breadcrumbs: [{ title: 'Torns', href: shiftsRoute() }] } });
</script>

<template>
    <Head :title="t('admin.shifts.title')" />
    <PageHeader :title="t('admin.shifts.title')" :description="t('admin.shifts.published')">
        <div class="flex rounded-lg border p-0.5">
            <Button size="sm" :variant="view === 'week' ? 'default' : 'ghost'" @click="navigate({ view: 'week' })">{{ t('admin.shifts.week') }}</Button>
            <Button size="sm" :variant="view === 'month' ? 'default' : 'ghost'" @click="navigate({ view: 'month' })">{{ t('admin.shifts.month') }}</Button>
        </div>
        <Button v-if="view === 'week'" variant="outline" size="sm" @click="copyPreviousWeek"><Copy class="size-4" /> {{ t('admin.shifts.copyPrevWeek') }}</Button>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <Button variant="outline" size="icon" @click="shiftPeriod(-1)"><ChevronLeft class="size-4" /></Button>
        <Button variant="outline" size="sm" @click="navigate({ date: todayIso() })">{{ t('common.today') }}</Button>
        <Button variant="outline" size="icon" @click="shiftPeriod(1)"><ChevronRight class="size-4" /></Button>
        <h2 class="ml-2 text-lg font-medium capitalize">{{ title }}</h2>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border bg-muted/30 p-2">
        <span class="px-1 text-xs font-medium text-muted-foreground uppercase">{{ t('admin.shifts.templates') }}</span>
        <div
            v-for="template in templates"
            :key="template.id"
            draggable="true"
            class="flex cursor-grab items-center gap-2 rounded-md px-2 py-1 text-xs text-white shadow-sm"
            :style="{ background: template.color ?? '#00056a' }"
            @dragstart="dragStart($event, { kind: 'template', id: template.id })"
            @click="openTemplate(template)"
        >
            <span class="font-medium">{{ template.name }}</span>
            <span class="opacity-80">{{ template.startTime }}–{{ template.endTime }}</span>
        </div>
        <Button size="sm" variant="ghost" @click="openTemplate(null)"><Plus class="size-4" /> {{ t('admin.shifts.newTemplate') }}</Button>
        <span class="ml-auto text-xs text-muted-foreground">{{ t('admin.shifts.dragHelp') }}</span>
    </div>

    <div v-if="view === 'week'" class="overflow-x-auto rounded-xl border">
        <table class="w-full min-w-[900px] table-fixed text-sm">
            <thead class="bg-muted/40 text-xs text-muted-foreground">
                <tr>
                    <th class="w-40 px-3 py-2 text-left">{{ t('admin.time.worker') }}</th>
                    <th v-for="(day, index) in days" :key="day" class="px-2 py-2 text-left" :class="{ 'text-primary': day === todayIso() }">
                        {{ tl('common.weekdays')[index] }} {{ formatDate(day, { day: 'numeric' }) }}
                    </th>
                    <th class="w-20 px-2 py-2 text-right">{{ t('admin.shifts.totalWeek') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="worker in workers" :key="worker.id" class="border-t">
                    <td class="px-3 py-2 font-medium">
                        <span class="mr-1.5 inline-block size-2.5 rounded-full" :style="{ background: worker.color ?? '#94a3b8' }" />
                        {{ worker.name }}
                    </td>
                    <td
                        v-for="day in days"
                        :key="day"
                        class="group h-16 border-l p-1 align-top"
                        :class="dragOver === `${worker.id}|${day}` ? 'bg-primary/10' : ''"
                        @dragover.prevent="dragOver = `${worker.id}|${day}`"
                        @dragleave="dragOver = null"
                        @drop.prevent="drop($event, day, worker.id)"
                    >
                        <div
                            v-for="shift in byCell.get(`${worker.id}|${day}`) ?? []"
                            :key="shift.id"
                            draggable="true"
                            class="mb-1 cursor-grab rounded px-1.5 py-1 text-[11px] leading-tight text-white"
                            :style="{ background: shiftColor(shift) }"
                            @dragstart="dragStart($event, { kind: 'shift', id: shift.id })"
                            @click="openEdit(shift)"
                        >
                            <div class="font-medium">{{ shift.startTime }}–{{ shift.endTime }}</div>
                            <div v-if="shift.notes" class="truncate opacity-80">{{ shift.notes }}</div>
                        </div>
                        <button class="hidden w-full rounded border border-dashed py-0.5 text-muted-foreground group-hover:block" @click="openCreate(day, worker.id)">
                            <Plus class="mx-auto size-3" />
                        </button>
                    </td>
                    <td class="px-2 py-2 text-right tabular-nums">{{ formatMinutes(workerMinutes(worker.id)) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-else class="overflow-hidden rounded-xl border">
        <div class="grid grid-cols-7 bg-muted/40 text-xs text-muted-foreground">
            <div v-for="name in tl('common.weekdaysLong')" :key="name" class="px-2 py-2">{{ name }}</div>
        </div>
        <div class="grid grid-cols-7">
            <div
                v-for="day in days"
                :key="day"
                class="group min-h-28 border-t border-l p-1"
                :class="[day.slice(0, 7) !== anchorMonth ? 'bg-muted/30 text-muted-foreground' : '', dragOver === day ? 'bg-primary/10' : '']"
                @dragover.prevent="dragOver = day"
                @dragleave="dragOver = null"
                @drop.prevent="drop($event, day, null)"
            >
                <div class="mb-1 flex items-center justify-between text-xs">
                    <span :class="{ 'rounded bg-primary px-1 text-primary-foreground': day === todayIso() }">{{ formatDate(day, { day: 'numeric' }) }}</span>
                    <button class="hidden text-muted-foreground group-hover:block" @click="openCreate(day, null)"><Plus class="size-3" /></button>
                </div>
                <div
                    v-for="shift in byDay.get(day) ?? []"
                    :key="shift.id"
                    draggable="true"
                    class="mb-0.5 cursor-grab truncate rounded px-1 py-0.5 text-[11px] text-white"
                    :style="{ background: shiftColor(shift) }"
                    @dragstart="dragStart($event, { kind: 'shift', id: shift.id })"
                    @click="openEdit(shift)"
                >
                    {{ shift.startTime }} {{ workerName(shift.userId) }}
                </div>
            </div>
        </div>
    </div>

    <Dialog v-model:open="shiftOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="saveShift">
                <DialogHeader>
                    <DialogTitle>{{ editing ? t('common.edit') : t('admin.shifts.addShift') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="t('admin.time.worker')" :error="form.errors.userId">
                        <SelectInput v-model="form.userId" :placeholder="'—'" :options="workers.map((w) => ({ value: w.id as number | null, label: w.name }))" />
                    </Field>
                    <Field :label="t('common.date')" :error="form.errors.date">
                        <Input v-model="form.date" type="date" required />
                    </Field>
                    <Field v-if="templates.length" :label="t('admin.shifts.templates')" class="sm:col-span-2">
                        <SelectInput
                            v-model="form.templateId"
                            :placeholder="t('common.none')"
                            :options="templates.map((tpl) => ({ value: tpl.id as number | null, label: `${tpl.name} (${tpl.startTime}–${tpl.endTime})` }))"
                            @update:model-value="applyTemplate"
                        />
                    </Field>
                    <Field :label="t('admin.shifts.start')" :error="form.errors.startTime"><Input v-model="form.startTime" type="time" required /></Field>
                    <Field :label="t('admin.shifts.end')" :error="form.errors.endTime"><Input v-model="form.endTime" type="time" required /></Field>
                    <Field :label="t('admin.shifts.break')"><Input v-model.number="form.breakMinutes" type="number" min="0" /></Field>
                    <Field :label="t('common.notes')"><Input v-model="form.notes" /></Field>
                </div>
                <DialogFooter class="gap-2">
                    <Button v-if="editing" type="button" variant="ghost" class="mr-auto text-destructive" @click="removeShift">
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="form.processing || !form.userId">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="templateOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="saveTemplate">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Pencil class="size-4" /> {{ t('admin.shifts.templates') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="t('common.name')" :error="templateForm.errors.name" class="sm:col-span-2"><Input v-model="templateForm.name" required /></Field>
                    <Field :label="t('admin.shifts.start')"><Input v-model="templateForm.startTime" type="time" required /></Field>
                    <Field :label="t('admin.shifts.end')"><Input v-model="templateForm.endTime" type="time" required /></Field>
                    <Field :label="t('admin.shifts.break')"><Input v-model.number="templateForm.breakMinutes" type="number" min="0" /></Field>
                    <Field :label="t('common.color')"><input v-model="templateForm.color" type="color" class="h-9 w-16 cursor-pointer rounded-md border" /></Field>
                </div>
                <DialogFooter class="gap-2">
                    <Button v-if="editingTemplate" type="button" variant="ghost" class="mr-auto text-destructive" @click="removeTemplate">
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="templateForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
