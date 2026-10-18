<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, ChevronDown, Copy, Download, KeyRound, Monitor, Pencil, Plus, Printer as PrinterIcon, ScrollText, Trash2 } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
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
import { tl, useI18n } from '@/i18n';
import { formatDateTime, toTrans } from '@/lib/format';
import { printing as printingRoute } from '@/routes/admin';
import * as destinationRoutes from '@/routes/admin/destinations';
import * as agentRoutes from '@/routes/admin/print-agents';
import * as printerRoutes from '@/routes/admin/printers';
import { log as logRoute } from '@/routes/admin/printing';
import * as deviceRoutes from '@/routes/admin/printing/devices';

type Mode = 'printer' | 'screen' | 'both';
type Destination = { id: number; name: Record<string, string>; code: string; mode: Mode; printerIds: number[] };
type Printer = {
    id: number;
    name: string;
    type: string;
    printAgentId: number | null;
    managed: boolean;
    output: 'escpos' | 'driver';
    codepage: 'cp858' | 'cp1252' | 'ascii';
    ip: string | null;
    port: number | null;
    model: string | null;
    systemName: string | null;
    paperWidth: number;
    isTicketPrinter: boolean;
    active: boolean;
    destinationIds: number[];
};

type TicketDevice = { id: number; name: string; type: 'cashier' | 'tablet'; ticketPrinterId: number | null };

type Agent = { id: number; name: string; online: boolean; lastSeenAt: string | null; machine: string | null; version: string | null; systemPrinters: string[] };

const props = defineProps<{
    destinations: Destination[];
    printers: Printer[];
    types: string[];
    agents: Agent[];
    devices: TicketDevice[];
    program: { available: boolean; version: string | null; size: number | null };
}>();

const { t, tr } = useI18n();

const modeOptions = computed(() => (['printer', 'screen', 'both'] as Mode[]).map((value) => ({ value, label: t(`admin.printing.modes.${value}`) })));
const typeOptions = computed(() => props.types.map((value) => ({ value, label: t(`admin.printing.types.${value}`) })));
const widthOptions = [32, 42, 48].map((value) => ({ value, label: `${value} (${value === 32 ? '58mm' : '80mm'})` }));
const codepageOptions = computed(() => (['cp858', 'cp1252', 'ascii'] as const).map((value) => ({ value, label: t(`admin.printing.codepages.${value}`) })));
const agentName = (id: number | null) => props.agents.find((a) => a.id === id)?.name ?? '';

// ------------------------------------------------------------ Windows print program

const howToOpen = ref(props.agents.length === 0);
const agentOpen = ref(false);
const agentForm = useForm({ name: '' });
const editingAgent = ref<Agent | null>(null);
const shownKey = ref<{ name: string; key: string } | null>(null);
const keyOpen = computed({ get: () => shownKey.value !== null, set: (v) => !v && (shownKey.value = null) });
const copied = ref(false);

function openAgent(agent: Agent | null): void {
    editingAgent.value = agent;
    agentForm.clearErrors();
    agentForm.name = agent?.name ?? '';
    agentOpen.value = true;
}

function saveAgent(): void {
    const options = { preserveScroll: true, onSuccess: () => (agentOpen.value = false) };

    if (editingAgent.value) {
        agentForm.patch(agentRoutes.update.url(editingAgent.value.id), options);
    } else {
        agentForm.post(agentRoutes.store.url(), options);
    }
}

function newKey(agent: Agent): void {
    if (confirm(t('admin.printing.program.newKeyConfirm'))) {
        router.post(agentRoutes.key.url(agent.id), {}, { preserveScroll: true });
    }
}

async function copyKey(): Promise<void> {
    if (!shownKey.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(shownKey.value.key);
        copied.value = true;
        toast.success(t('admin.printing.program.copied'));
    } catch {
        // Without clipboard access the key stays selectable in the box.
    }
}

// The key comes once, as flash data, right after creating the computer or renewing it.
let stopFlash: (() => void) | null = null;

onMounted(() => {
    stopFlash = router.on('flash', (event) => {
        const key = (event as CustomEvent).detail?.flash?.agentKey as { name: string; key: string } | undefined;

        if (key) {
            copied.value = false;
            shownKey.value = key;
        }
    });
});

onUnmounted(() => stopFlash?.());

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
    type: 'system',
    output: 'escpos' as Printer['output'],
    codepage: 'cp858' as Printer['codepage'],
    ip: '',
    port: 9100 as number | undefined,
    model: '',
    systemName: '',
    paperWidth: 48,
    isTicketPrinter: false,
    active: true,
    destinationIds: [] as number[],
});

function openPrinter(printer: Printer | null): void {
    editingPrinter.value = printer;
    printerForm.clearErrors();
    printerForm.name = printer?.name ?? '';
    printerForm.type = printer?.type ?? 'system';
    printerForm.output = printer?.output ?? 'escpos';
    printerForm.codepage = printer?.codepage ?? 'cp858';
    printerForm.ip = printer?.ip ?? '';
    printerForm.port = printer?.port ?? 9100;
    printerForm.model = printer?.model ?? '';
    printerForm.systemName = printer?.systemName ?? printer?.name ?? '';
    printerForm.paperWidth = printer?.paperWidth ?? 48;
    printerForm.isTicketPrinter = printer?.isTicketPrinter ?? false;
    printerForm.active = printer?.active ?? true;
    printerForm.destinationIds = [...(printer?.destinationIds ?? [])];
    printerOpen.value = true;
}

function savePrinter(): void {
    printerForm.transform((data) => ({ ...data, ip: data.ip || null, model: data.model || null, systemName: data.systemName || data.name }));

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

// Printers of the print program are set up in the program; here only what they print.
const managed = computed(() => editingPrinter.value?.managed === true);
const isOutdated = (agent: Agent) => !!agent.version && !!props.program.version && agent.version !== props.program.version;

// ------------------------------------------------------------ Tickets per device

const generalTicketPrinter = computed(() => props.printers.find((p) => p.active && p.isTicketPrinter) ?? null);
const deviceTicketOptions = computed(() => [
    {
        value: null as number | null,
        label: generalTicketPrinter.value ? t('admin.printing.deviceTicketsGeneral', { name: generalTicketPrinter.value.name }) : t('admin.printing.deviceTicketsNone'),
    },
    ...props.printers.filter((p) => p.active).map((p) => ({ value: p.id as number | null, label: p.name })),
]);

function setDeviceTicketPrinter(device: TicketDevice, printerId: number | null): void {
    router.patch(deviceRoutes.update.url(device.id), { ticketPrinterId: printerId }, { preserveScroll: true });
}

const deleting = ref<{ kind: 'destination' | 'printer' | 'agent'; id: number; label: string } | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function remove(): void {
    const target = deleting.value;

    if (!target) {
        return;
    }

    const url = { destination: destinationRoutes.destroy.url, printer: printerRoutes.destroy.url, agent: agentRoutes.destroy.url }[target.kind](target.id);

    router.delete(url, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
            destinationOpen.value = false;
            printerOpen.value = false;
            agentOpen.value = false;
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

    <section class="mb-8 rounded-xl border bg-card p-4 sm:p-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#00056a] text-white"><Monitor class="size-6" /></div>
            <div class="min-w-0 flex-1 basis-80">
                <h2 class="text-lg font-semibold">{{ t('admin.printing.program.title') }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ t('admin.printing.program.intro') }}</p>
            </div>
            <div class="flex flex-col items-stretch gap-1 sm:items-end">
                <Button v-if="program.available" as-child class="bg-[#00056a]">
                    <a :href="agentRoutes.download.url()"><Download class="size-4" /> {{ t('admin.printing.program.download') }}</a>
                </Button>
                <span v-if="program.available" class="text-xs text-muted-foreground">
                    {{ t('admin.printing.program.downloadInfo', { size: Math.round((program.size ?? 0) / 1048576), version: program.version ?? '—' }) }}
                </span>
                <p v-else class="max-w-64 text-xs text-amber-700">{{ t('admin.printing.program.notBuilt') }}</p>
            </div>
        </div>

        <button type="button" class="mt-4 flex items-center gap-1 text-sm font-medium text-[#00056a] dark:text-blue-300" @click="howToOpen = !howToOpen">
            <ChevronDown class="size-4 transition" :class="howToOpen ? 'rotate-180' : ''" /> {{ t('admin.printing.program.howTo') }}
        </button>
        <div v-if="howToOpen" class="mt-3 grid gap-4 lg:grid-cols-[3fr_2fr]">
            <ol class="space-y-2 text-sm">
                <li v-for="(step, i) in tl('admin.printing.program.steps')" :key="i" class="flex gap-3">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#00056a] text-xs font-bold text-white">{{ i + 1 }}</span>
                    <span>{{ step }}</span>
                </li>
            </ol>
            <ul class="list-disc space-y-1.5 rounded-lg bg-muted/40 p-3 pl-7 text-xs text-muted-foreground">
                <li v-for="(note, i) in tl('admin.printing.program.notes')" :key="i">{{ note }}</li>
            </ul>
        </div>

        <div class="mt-5 mb-2 flex items-center justify-between">
            <h3 class="font-medium">{{ t('admin.printing.program.computers') }}</h3>
            <Button size="sm" @click="openAgent(null)"><Plus class="size-4" /> {{ t('admin.printing.program.addComputer') }}</Button>
        </div>
        <p v-if="!agents.length" class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">{{ t('admin.printing.program.none') }}</p>
        <div class="grid gap-2 md:grid-cols-2">
            <div v-for="agent in agents" :key="agent.id" class="flex items-start gap-3 rounded-xl border p-3">
                <span class="mt-1.5 size-2.5 shrink-0 rounded-full" :class="agent.online ? 'bg-emerald-500' : 'bg-red-500'" />
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ agent.name }} <span v-if="agent.machine" class="text-xs font-normal text-muted-foreground">· {{ agent.machine }}</span></div>
                    <div class="text-xs" :class="agent.online ? 'text-emerald-700' : 'text-red-700'">
                        {{ agent.online ? t('admin.printing.program.online') : agent.lastSeenAt ? t('admin.printing.program.offline') : t('admin.printing.program.never') }}
                        <span v-if="agent.lastSeenAt && !agent.online" class="text-muted-foreground">· {{ t('admin.printing.program.lastSeen', { when: formatDateTime(agent.lastSeenAt) }) }}</span>
                        <span v-if="agent.version" class="text-muted-foreground"> · v{{ agent.version }}</span>
                        <span v-if="isOutdated(agent)" class="text-amber-700"> ({{ t('admin.printing.program.outdated', { version: program.version ?? '' }) }})</span>
                        · {{ t('admin.printing.program.printersCount', { count: printers.filter((p) => p.printAgentId === agent.id).length }) }}
                    </div>
                    <div v-if="agent.systemPrinters.length" class="mt-1 truncate text-xs text-muted-foreground" :title="agent.systemPrinters.join(', ')">
                        {{ t('admin.printing.program.windowsPrinters', { list: agent.systemPrinters.join(', ') }) }}
                    </div>
                </div>
                <Button variant="outline" size="sm" @click="newKey(agent)"><KeyRound class="size-4" /> {{ t('admin.printing.program.newKey') }}</Button>
                <Button variant="ghost" size="icon" @click="openAgent(agent)"><Pencil class="size-4" /></Button>
            </div>
        </div>
    </section>

    <div class="mb-6 space-y-2 rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground">
        <p>{{ t('admin.printing.systemNotice') }}</p>
        <p>{{ t('admin.printing.escposNotice') }}</p>
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
            <p v-if="agents.length" class="mb-3 text-xs text-muted-foreground">{{ t('admin.printing.printersFromProgram') }}</p>
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
                            <Badge v-if="printer.printAgentId" variant="outline" class="gap-1">
                                <span class="size-1.5 rounded-full" :class="agents.find((a) => a.id === printer.printAgentId)?.online ? 'bg-emerald-500' : 'bg-red-500'" />
                                {{ t('admin.printing.viaBadge', { name: agentName(printer.printAgentId) }) }}
                            </Badge>
                            <span v-if="printer.type === 'system' && printer.systemName" class="text-muted-foreground">{{ printer.systemName }}</span>
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

    <section class="mt-8">
        <h2 class="font-medium">{{ t('admin.printing.deviceTickets') }}</h2>
        <p class="mt-1 mb-3 text-sm text-muted-foreground">{{ t('admin.printing.deviceTicketsHelp') }}</p>
        <p v-if="!devices.length" class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">{{ t('admin.printing.noDevices') }}</p>
        <div class="grid gap-2 md:grid-cols-2">
            <div v-for="device in devices" :key="device.id" class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3">
                <Monitor class="size-5 text-muted-foreground" />
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ device.name }}</div>
                    <div class="text-xs text-muted-foreground">{{ t(`device.types.${device.type}`) }}</div>
                </div>
                <div class="w-full sm:w-64">
                    <SelectInput
                        :model-value="device.ticketPrinterId"
                        :options="deviceTicketOptions"
                        @update:model-value="(value) => setDeviceTicketPrinter(device, (value as number | null) ?? null)"
                    />
                </div>
            </div>
        </div>
    </section>

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
                <div v-if="managed && editingPrinter" class="rounded-lg border bg-muted/40 p-3 text-sm">
                    <div class="font-medium">{{ editingPrinter.name }}</div>
                    <div class="mt-0.5 text-muted-foreground">
                        {{ editingPrinter.type === 'escpos_network' ? `${editingPrinter.ip}:${editingPrinter.port}` : editingPrinter.systemName }}
                        · {{ t(`admin.printing.outputs.${editingPrinter.output}`) }} · {{ editingPrinter.paperWidth }} col.
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">{{ t('admin.printing.managedHelp', { name: agentName(editingPrinter.printAgentId) }) }}</p>
                </div>
                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <Field :label="t('common.name')" :error="printerForm.errors.name" class="sm:col-span-2">
                        <Input v-model="printerForm.name" required />
                    </Field>
                    <Field :label="t('admin.printing.type')">
                        <SelectInput v-model="printerForm.type" :options="typeOptions" />
                    </Field>
                    <Field :label="t('admin.printing.paperWidth')">
                        <SelectInput v-model="printerForm.paperWidth" :options="widthOptions" />
                    </Field>
                    <Field
                        v-if="printerForm.type === 'system'"
                        :label="t('admin.printing.systemName')"
                        :error="printerForm.errors.systemName"
                        :help="t('admin.printing.systemNameHelp')"
                        class="sm:col-span-2"
                    >
                        <Input v-model="printerForm.systemName" :placeholder="printerForm.name || t('admin.printing.systemNamePlaceholder')" />
                    </Field>
                    <Field
                        v-if="printerForm.type === 'escpos_network'"
                        :label="t('admin.printing.codepage')"
                        :help="t('admin.printing.codepageHelp')"
                        class="sm:col-span-2"
                    >
                        <SelectInput v-model="printerForm.codepage" :options="codepageOptions" />
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
                        v-if="editingPrinter && !managed"
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

    <Dialog v-model:open="agentOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="saveAgent">
                <DialogHeader>
                    <DialogTitle>{{ editingAgent ? t('common.edit') : t('admin.printing.program.addComputer') }}</DialogTitle>
                </DialogHeader>
                <Field :label="t('admin.printing.program.computerName')" :error="agentForm.errors.name">
                    <Input v-model="agentForm.name" :placeholder="t('admin.printing.program.computerNamePlaceholder')" required maxlength="60" />
                </Field>
                <DialogFooter class="gap-2">
                    <Button
                        v-if="editingAgent"
                        type="button"
                        variant="ghost"
                        class="mr-auto text-destructive"
                        @click="deleting = { kind: 'agent', id: editingAgent.id, label: editingAgent.name }"
                    >
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="agentForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="keyOpen">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><KeyRound class="size-5" /> {{ t('admin.printing.program.keyTitle', { name: shownKey?.name ?? '' }) }}</DialogTitle>
            </DialogHeader>
            <p class="text-sm text-muted-foreground">{{ t('admin.printing.program.keyHelp') }}</p>
            <textarea readonly rows="3" class="w-full resize-none rounded-lg border bg-muted/40 p-3 font-mono text-sm break-all" :value="shownKey?.key" @focus="($event.target as HTMLTextAreaElement).select()" />
            <DialogFooter>
                <Button class="h-11 w-full bg-[#00056a] sm:w-auto" @click="copyKey">
                    <component :is="copied ? Check : Copy" class="size-4" /> {{ copied ? t('admin.printing.program.copied') : t('admin.printing.program.copy') }}
                </Button>
            </DialogFooter>
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
