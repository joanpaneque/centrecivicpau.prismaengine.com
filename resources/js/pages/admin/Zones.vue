<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { GripVertical, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import TransInput from '@/components/admin/TransInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import { toTrans } from '@/lib/format';
import { zones as zonesRoute } from '@/routes/admin';
import { destroy, reorder, store, update } from '@/routes/admin/zones';

type Zone = {
    id: number;
    name: Record<string, string>;
    appliesTerraceSurcharge: boolean;
    isBar: boolean;
    tablesCount: number;
};

const props = defineProps<{ zones: Zone[] }>();

const { t, tr } = useI18n();
const list = ref<Zone[]>([...props.zones]);
watch(() => props.zones, (value) => (list.value = [...value]));

function saveOrder(): void {
    router.post(reorder.url(), { ids: list.value.map((z) => z.id) }, { preserveScroll: true, preserveState: true });
}

const formOpen = ref(false);
const editing = ref<Zone | null>(null);
const form = useForm({ name: { ca: '', es: '' }, appliesTerraceSurcharge: false, isBar: false });

function openForm(zone: Zone | null): void {
    editing.value = zone;
    form.clearErrors();
    form.name = toTrans(zone?.name);
    form.appliesTerraceSurcharge = zone?.appliesTerraceSurcharge ?? false;
    form.isBar = zone?.isBar ?? false;
    formOpen.value = true;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false) };

    if (editing.value) {
        form.patch(update.url(editing.value.id), options);
    } else {
        form.post(store.url(), options);
    }
}

const deleting = ref<Zone | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function remove(): void {
    if (deleting.value) {
        router.delete(destroy.url(deleting.value.id), { preserveScroll: true, onFinish: () => (deleting.value = null) });
    }
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Zones', href: zonesRoute() }] } });
</script>

<template>
    <Head :title="t('admin.zones.title')" />
    <PageHeader :title="t('admin.zones.title')" :description="t('admin.zones.description')">
        <Button @click="openForm(null)"><Plus class="size-4" /> {{ t('common.new') }}</Button>
    </PageHeader>

    <p class="mb-4 text-sm text-muted-foreground">{{ t('admin.zones.editFloor') }}</p>

    <VueDraggable v-model="list" handle=".handle" :animation="150" class="max-w-2xl space-y-2" @end="saveOrder">
        <div v-for="zone in list" :key="zone.id" class="flex items-center gap-3 rounded-xl border bg-card p-3">
            <GripVertical class="handle size-5 cursor-grab text-muted-foreground" />
            <div class="flex-1">
                <div class="font-medium">{{ tr(zone.name) }}</div>
                <div class="mt-1 flex flex-wrap gap-1 text-xs">
                    <Badge variant="secondary">{{ zone.tablesCount }} {{ t('admin.zones.tables').toLowerCase() }}</Badge>
                    <Badge v-if="zone.appliesTerraceSurcharge">{{ t('admin.zones.surcharge') }}</Badge>
                    <Badge v-if="zone.isBar" variant="outline">{{ t('admin.zones.isBar') }}</Badge>
                </div>
            </div>
            <Button variant="ghost" size="icon" @click="openForm(zone)"><Pencil class="size-4" /></Button>
            <Button variant="ghost" size="icon" class="text-destructive" @click="deleting = zone"><Trash2 class="size-4" /></Button>
        </div>
    </VueDraggable>

    <Dialog v-model:open="formOpen">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-4" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{ editing ? t('common.edit') : t('common.new') }}</DialogTitle>
                </DialogHeader>
                <TransInput v-model="form.name" :label="t('common.name')" :error="form.errors.name || form.errors['name.ca']" />
                <Label class="flex items-center gap-2">
                    <Checkbox v-model="form.appliesTerraceSurcharge" /> {{ t('admin.zones.surcharge') }}
                </Label>
                <Label class="flex items-center gap-2">
                    <Checkbox v-model="form.isBar" /> {{ t('admin.zones.isBar') }}
                </Label>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('common.delete')"
        :description="deleting ? tr(deleting.name) : ''"
        :confirm-label="t('common.delete')"
        @confirm="remove"
    />
</template>
