<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Ban, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { devices as devicesRoute } from '@/routes/admin';
import { destroy, update } from '@/routes/admin/devices';

type DeviceType = 'tablet' | 'cashier' | 'kds' | 'clock';
type Device = {
    id: number;
    uuid: string;
    name: string;
    type: DeviceType;
    active: boolean;
    series: string | null;
    lastNumber: number | null;
    lastSeenAt: string | null;
    createdAt: string | null;
};

defineProps<{ devices: Device[] }>();

const { t } = useI18n();
const typeOptions = computed(() => (['tablet', 'cashier', 'kds', 'clock'] as DeviceType[]).map((value) => ({ value, label: t(`device.types.${value}`) })));

const editing = ref<Device | null>(null);
const form = useForm({ name: '', type: 'tablet' as DeviceType });

function openEdit(device: Device): void {
    editing.value = device;
    form.name = device.name;
    form.type = device.type;
    form.clearErrors();
}

function save(): void {
    if (editing.value) {
        form.patch(update.url(editing.value.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
    }
}

const revoking = ref<Device | null>(null);
const revokeOpen = computed({ get: () => revoking.value !== null, set: (v) => !v && (revoking.value = null) });

function revoke(): void {
    if (revoking.value) {
        router.delete(destroy.url(revoking.value.id), { preserveScroll: true, onFinish: () => (revoking.value = null) });
    }
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Dispositius', href: devicesRoute() }] } });
</script>

<template>
    <Head :title="t('admin.devices.title')" />
    <PageHeader :title="t('admin.devices.title')" :description="t('admin.devices.description')" />

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div
            v-for="device in devices"
            :key="device.id"
            class="rounded-xl border bg-card p-4"
            :class="{ 'opacity-50': !device.active }"
        >
            <div class="flex items-start justify-between gap-2">
                <div>
                    <div class="font-medium">{{ device.name }}</div>
                    <div class="text-sm text-muted-foreground">{{ t(`device.types.${device.type}`) }}</div>
                </div>
                <Badge v-if="!device.active" variant="destructive">{{ t('admin.devices.revoked') }}</Badge>
                <Badge v-else-if="device.series" variant="secondary">
                    {{ t('admin.devices.series') }} {{ device.series }} · {{ device.lastNumber ?? 0 }}
                </Badge>
            </div>
            <div class="mt-3 text-xs text-muted-foreground">
                {{ t('admin.devices.lastSeen') }}:
                {{ device.lastSeenAt ? formatDateTime(device.lastSeenAt) : t('admin.devices.never') }}
            </div>
            <div v-if="device.active" class="mt-3 flex gap-2">
                <Button size="sm" variant="outline" @click="openEdit(device)">
                    <Pencil class="size-4" /> {{ t('common.edit') }}
                </Button>
                <Button size="sm" variant="ghost" class="text-destructive" @click="revoking = device">
                    <Ban class="size-4" /> {{ t('admin.devices.revoke') }}
                </Button>
            </div>
        </div>
        <p v-if="devices.length === 0" class="text-sm text-muted-foreground">{{ t('common.empty') }}</p>
    </div>

    <Dialog :open="editing !== null" @update:open="(v) => !v && (editing = null)">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{ t('common.edit') }}</DialogTitle>
                </DialogHeader>
                <Field :label="t('device.name')" :error="form.errors.name">
                    <Input v-model="form.name" required />
                </Field>
                <Field :label="t('device.type')" :error="form.errors.type" :help="t(`device.typeHelp.${form.type}`)">
                    <SelectInput v-model="form.type" :options="typeOptions" />
                </Field>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="revokeOpen"
        destructive
        :title="t('admin.devices.revoke')"
        :description="revoking?.name"
        :confirm-label="t('admin.devices.revoke')"
        @confirm="revoke"
    />
</template>
