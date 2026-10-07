<script setup lang="ts">
import { reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { remove, upsert } from '@/pos/store';
import type { DiningTable, TableShape } from '@/pos/types';

const props = defineProps<{ table: DiningTable }>();
const emit = defineEmits<{ close: [] }>();

const open = ref(true);
const busy = ref(false);
const form = reactive({
    label: props.table.label,
    seats: props.table.seats,
    shape: props.table.shape as TableShape,
    isAuxiliary: props.table.isAuxiliary,
    width: props.table.width,
    height: props.table.height,
    rotation: props.table.rotation ?? 0,
});

const shapes: TableShape[] = ['square', 'round', 'rect', 'stool'];

function setShape(shape: TableShape): void {
    form.shape = shape;
    form.width = shape === 'rect' ? 160 : shape === 'stool' ? 70 : 90;
    form.height = shape === 'stool' ? 70 : 90;
}

async function save(): Promise<void> {
    busy.value = true;

    try {
        const response = await api<{ table: DiningTable }>('PATCH', `/tpv/api/tables/${props.table.id}`, form);
        upsert('tables', response.table);
        close();
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    } finally {
        busy.value = false;
    }
}

async function destroy(): Promise<void> {
    if (!confirm(t('common.areYouSure'))) {
        return;
    }

    try {
        await api('DELETE', `/tpv/api/tables/${props.table.id}`);
        remove('tables', props.table.id);
        close();
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('floor.editorOffline'));
    }
}

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('reservations.table') }} {{ table.label }}</DialogTitle>
                <DialogDescription class="sr-only">{{ t('floor.editMode') }}</DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label>{{ t('floor.tableLabel') }}</Label>
                        <Input v-model="form.label" class="h-11" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>{{ t('floor.seats') }}</Label>
                        <Input v-model.number="form.seats" type="number" min="1" max="30" class="h-11" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label>{{ t('floor.shape') }}</Label>
                    <div class="grid grid-cols-4 gap-2">
                        <button
                            v-for="shape in shapes"
                            :key="shape"
                            type="button"
                            class="flex flex-col items-center gap-1 rounded-lg border-2 p-2 text-xs"
                            :class="form.shape === shape ? 'border-[#00056a] bg-[#00056a]/5' : 'border-slate-200'"
                            @click="setShape(shape)"
                        >
                            <span class="block border-2 border-slate-500" :class="[shape === 'round' || shape === 'stool' ? 'rounded-full' : 'rounded', shape === 'rect' ? 'h-5 w-9' : shape === 'stool' ? 'size-4' : 'size-6']" />
                            {{ t(`floor.shapes.${shape}`) }}
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-1.5">
                        <Label>↔</Label>
                        <Input v-model.number="form.width" type="number" min="40" max="400" step="10" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>↕</Label>
                        <Input v-model.number="form.height" type="number" min="40" max="400" step="10" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>{{ t('floor.rotation') }}</Label>
                        <Input v-model.number="form.rotation" type="number" min="0" max="359" step="15" />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.isAuxiliary" type="checkbox" class="size-5" />
                    {{ t('floor.auxiliary') }}
                </label>
            </div>

            <DialogFooter class="flex-row justify-between gap-2 sm:justify-between">
                <Button variant="destructive" @click="destroy">{{ t('floor.deleteTable') }}</Button>
                <Button :disabled="busy" @click="save">{{ t('common.save') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
