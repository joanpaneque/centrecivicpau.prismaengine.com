<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Printer as PrinterIcon, ScrollText, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import TransInput from '@/components/admin/TransInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import { toTrans } from '@/lib/format';
import { printing as printingRoute } from '@/routes/admin';
import * as destinationRoutes from '@/routes/admin/destinations';
import * as printerRoutes from '@/routes/admin/printers';
import { log as logRoute } from '@/routes/admin/printing';

type Mode = 'printer' | 'screen' | 'both';
type Destination = { id: number; name: Record<string, string>; code: string; mode: Mode; printerIds: number[] };
type Printer = {
    id: number;
    name: string;
    type: string;
    ip: string | null;
    port: number | null;
    model: string | null;
    paperWidth: number;
    isTicketPrinter: boolean;
    active: boolean;
    destinationIds: number[];
};

const props = defineProps<{ destinations: Destination[]; printers: Printer[]; types: string[] }>();

const { t, tr } = useI18n();

const modeOptions = computed(() => (['printer', 'screen', 'both'] as Mode[]).map((value) => ({ value, label: t(`admin.printing.modes.${value}`) })));
const typeOptions = computed(() => props.types.map((value) => ({ value, label: t(`admin.printing.types.${value}`) })));
const widthOptions = [32, 42, 48].map((value) => ({ value, label: `${value} (${value === 32 ? '58mm' : '80mm'})` }));

function toggle(list: number[], id: number): void {
    const index = list.indexOf(id);

    if (index >= 0) {
        list.splice(index, 1);
    } else {
        list.push(id);
    }
}

const destinationOpen = ref(false);
const editingDestination = ref<Destination | null>(null);
const destinationForm = useForm({ name: { ca: '', es: '' }, code: '', mode: 'both' as Mode, printerIds: [] as number[] });

function openDestination(destination: Destination | null): void {
    editingDestination.value = destination;
    destinationForm.clearErrors();
    destinationForm.name = toTrans(destination?.name);
    destinationForm.code = destination?.code ?? '';
    destinationForm.mode = destination?.mode ?? 'both';
    destinationForm.printerIds = [...(destination?.printerIds ?? [])];
    destinationOpen.value = true;
}

function saveDestination(): void {
    const options = { preserveScroll: true, onSuccess: () => (destinationOpen.value = false) };

    if (editingDestination.value) {
        destinationForm.patch(destinationRoutes.update.url(editingDestination.value.id), options);
    } else {
        destinationForm.post(destinationRoutes.store.url(), options);
    }
}

const printerOpen = ref(false);
const editingPrinter = ref<Printer | null>(null);
const printerForm = useForm({
    name: '',
    type: 'simulated',
    ip: '',
    port: 9100 as number | undefined,
    model: '',
    paperWidth: 42,
    isTicketPrinter: false,
    active: true,
    destinationIds: [] as number[],
});

function openPrinter(printer: Printer | null): void {
    editingPrinter.value = printer;
    printerForm.clearErrors();
    printerForm.name = printer?.name ?? '';
    printerForm.type = printer?.type ?? 'simulated';
    printerForm.ip = printer?.ip ?? '';
    printerForm.port = printer?.port ?? 9100;
    printerForm.model = printer?.model ?? '';
    printerForm.paperWidth = printer?.paperWidth ?? 42;
    printerForm.isTicketPrinter = printer?.isTicketPrinter ?? false;
    printerForm.active = printer?.active ?? true;
    printerForm.destinationIds = [...(printer?.destinationIds ?? [])];
    printerOpen.value = true;
}

function savePrinter(): void {
    printerForm.transform((data) => ({ ...data, ip: data.ip || null, model: data.model || null }));

    const options = { preserveScroll: true, onSuccess: () => (printerOpen.value = false) };

    if (editingPrinter.value) {
        printerForm.patch(printerRoutes.update.url(editingPrinter.value.id), options);
    } else {
        printerForm.post(printerRoutes.store.url(), options);
    }
}

function testPrint(printer: Printer): void {
    router.post(printerRoutes.test.url(printer.id), {}, { preserveScroll: true });
}

const deleting = ref<{ kind: 'destination' | 'printer'; id: number; label: string } | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function remove(): void {
    const target = deleting.value;

    if (!target) {
        return;
    }

    router.delete(target.kind === 'destination' ? destinationRoutes.destroy.url(target.id) : printerRoutes.destroy.url(target.id), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
            destinationOpen.value = false;
            printerOpen.value = false;
        },
    });
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Impressió', href: printingRoute() }] } });
</script>

<template>
    <Head :title="t('admin.printing.title')" />
    <PageHeader :title="t('admin.printing.title')" :description="t('admin.printing.description')">
        <Button variant="outline" as-child>
            <Link :href="logRoute()"><ScrollText class="size-4" /> {{ t('admin.printing.log') }}</Link>
        </Button>
    </PageHeader>

    <div class="mb-6 rounded-lg border border-dashed bg-muted/30 p-3 text-sm text-muted-foreground">
        {{ t('admin.printing.simulatedNotice') }}
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <section>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-medium">{{ t('admin.printing.destinations') }}</h2>
                <Button size="sm" @click="openDestination(null)"><Plus class="size-4" /> {{ t('common.new') }}</Button>
            </div>
            <div class="space-y-2">
                <div v-for="destination in destinations" :key="destination.id" class="flex items-center gap-3 rounded-xl border bg-card p-3">
                    <div class="flex-1">
                        <div class="font-medium">{{ tr(destination.name) }} <span class="text-xs text-muted-foreground">({{ destination.code }})</span></div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <Badge variant="secondary">{{ t(`admin.printing.modes.${destination.mode}`) }}</Badge>
                            <Badge v-for="id in destination.printerIds" :key="id" variant="outline">
                                {{ printers.find((p) => p.id === id)?.name }}
                            </Badge>
                        </div>
                    </div>
                    <Button variant="ghost" size="icon" @click="openDestination(destination)"><Pencil class="size-4" /></Button>
                </div>
            </div>
        </section>

        <section>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-medium">{{ t('admin.printing.printers') }}</h2>
                <Button size="sm" @click="openPrinter(null)"><Plus class="size-4" /> {{ t('common.new') }}</Button>
            </div>
            <div class="space-y-2">
                <div
                    v-for="printer in printers"
                    :key="printer.id"
                    class="flex items-center gap-3 rounded-xl border bg-card p-3"
                    :class="{ 'opacity-50': !printer.active }"
                >
                    <PrinterIcon class="size-5 text-muted-foreground" />
                    <div class="flex-1">
                        <div class="font-medium">{{ printer.name }}</div>
                        <div class="mt-1 flex flex-wrap gap-1 text-xs">
                            <Badge variant="secondary">{{ t(`admin.printing.types.${printer.type}`) }}</Badge>
                            <Badge v-if="printer.isTicketPrinter">{{ t('admin.printing.isTicketPrinter') }}</Badge>
                            <span v-if="printer.ip" class="text-muted-foreground">{{ printer.ip }}:{{ printer.port }}</span>
                            <span class="text-muted-foreground">{{ printer.paperWidth }} col.</span>
                        </div>
                    </div>
                    <Button variant="outline" size="sm" @click="testPrint(printer)">{{ t('admin.printing.testPrint') }}</Button>
                    <Button variant="ghost" size="icon" @click="openPrinter(printer)"><Pencil class="size-4" /></Button>
                </div>
            </div>
        </section>
    </div>

    <Dialog v-model:open="destinationOpen">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-4" @submit.prevent="saveDestination">
                <DialogHeader>
                    <DialogTitle>{{ t('admin.printing.destination') }}</DialogTitle>
                </DialogHeader>
                <TransInput v-model="destinationForm.name" :label="t('common.name')" :error="destinationForm.errors['name.ca'] || destinationForm.errors.name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="t('admin.printing.code')" :error="destinationForm.errors.code">
                        <Input v-model="destinationForm.code" required />
                    </Field>
                    <Field :label="t('admin.printing.mode')">
                        <SelectInput v-model="destinationForm.mode" :options="modeOptions" />
                    </Field>
                </div>
                <Field :label="t('admin.printing.printers')">
                    <div class="flex flex-wrap gap-2">
                        <Label v-for="printer in printers" :key="printer.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm font-normal">
                            <Checkbox :model-value="destinationForm.printerIds.includes(printer.id)" @update:model-value="toggle(destinationForm.printerIds, printer.id)" />
                            {{ printer.name }}
                        </Label>
                    </div>
                </Field>
                <DialogFooter class="gap-2">
                    <Button
                        v-if="editingDestination"
                        type="button"
                        variant="ghost"
                        class="mr-auto text-destructive"
                        @click="deleting = { kind: 'destination', id: editingDestination.id, label: tr(editingDestination.name) }"
                    >
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="destinationForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="printerOpen">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-4" @submit.prevent="savePrinter">
                <DialogHeader>
                    <DialogTitle>{{ t('admin.printing.printer') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="t('common.name')" :error="printerForm.errors.name" class="sm:col-span-2">
                        <Input v-model="printerForm.name" required />
                    </Field>
                    <Field :label="t('admin.printing.type')">
                        <SelectInput v-model="printerForm.type" :options="typeOptions" />
                    </Field>
                    <Field :label="t('admin.printing.paperWidth')">
                        <SelectInput v-model="printerForm.paperWidth" :options="widthOptions" />
                    </Field>
                    <template v-if="printerForm.type === 'escpos_network'">
                        <Field :label="t('admin.printing.host')" :error="printerForm.errors.ip">
                            <Input v-model="printerForm.ip" placeholder="192.168.1.50" />
                        </Field>
                        <Field :label="t('admin.printing.port')" :error="printerForm.errors.port">
                            <Input v-model.number="printerForm.port" type="number" />
                        </Field>
                    </template>
                </div>
                <div class="flex flex-wrap gap-4">
                    <Label class="flex items-center gap-2"><Checkbox v-model="printerForm.isTicketPrinter" /> {{ t('admin.printing.isTicketPrinter') }}</Label>
                    <Label class="flex items-center gap-2"><Checkbox v-model="printerForm.active" /> {{ t('common.active') }}</Label>
                </div>
                <Field :label="t('admin.printing.assignedDestinations')">
                    <div class="flex flex-wrap gap-2">
                        <Label v-for="destination in destinations" :key="destination.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm font-normal">
                            <Checkbox :model-value="printerForm.destinationIds.includes(destination.id)" @update:model-value="toggle(printerForm.destinationIds, destination.id)" />
                            {{ tr(destination.name) }}
                        </Label>
                    </div>
                </Field>
                <DialogFooter class="gap-2">
                    <Button
                        v-if="editingPrinter"
                        type="button"
                        variant="ghost"
                        class="mr-auto text-destructive"
                        @click="deleting = { kind: 'printer', id: editingPrinter.id, label: editingPrinter.name }"
                    >
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="printerForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('common.delete')"
        :description="deleting?.label"
        :confirm-label="t('common.delete')"
        @confirm="remove"
    />
</template>
