<script setup lang="ts">
import { Loader2, Printer as PrinterIcon, Send } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import SelectInput from '@/components/admin/SelectInput.vue';
import PrintPreview from '@/components/pos/PrintPreview.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import { api } from '@/lib/http';
import { simulationJobs } from '@/pos/simulate';
import type { SimulatedJob, SimulationData } from '@/pos/simulate';
import { simulate, simulation } from '@/routes/admin/tickets';

export type SimulationDevice = { id: number; name: string; type: string };

const props = defineProps<{
    ticket: { id: number; fullNumber: string; hasOrder: boolean } | null;
    devices: SimulationDevice[];
    currentDeviceId: number | null;
}>();
const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();

const open = computed({ get: () => props.ticket !== null, set: (v) => !v && emit('close') });
const deviceId = ref<number | null>(null);
const send = ref(true);
const printTicket = ref(true);
const mark = ref(true);
const data = ref<SimulationData | null>(null);
const loading = ref(false);
const sending = ref(false);
const shown = ref<number | null>(null);

const deviceOptions = computed(() => [
    { value: null as number | null, label: t('admin.tickets.simulation.noDevice') },
    ...props.devices.map((d) => ({
        value: d.id as number | null,
        label: `${d.name} · ${t(`device.types.${d.type}`)}${d.id === props.currentDeviceId ? ` (${t('admin.tickets.simulation.thisBrowser')})` : ''}`,
    })),
]);

watch(
    () => props.ticket,
    (ticket) => {
        if (!ticket) {
            return;
        }

        deviceId.value = props.currentDeviceId ?? props.devices.find((d) => d.type === 'cashier')?.id ?? null;
        send.value = ticket.hasOrder;
        printTicket.value = true;
        mark.value = true;
        shown.value = null;
        void load();
    },
    { immediate: true },
);

watch(deviceId, () => props.ticket && void load());

async function load(): Promise<void> {
    if (!props.ticket) {
        return;
    }

    loading.value = true;

    try {
        data.value = await api<SimulationData>('GET', simulation.url(props.ticket.id, { query: { device: deviceId.value ?? undefined } }));
    } catch {
        data.value = null;
        toast.error(t('common.error'));
    } finally {
        loading.value = false;
    }
}

const jobs = computed<SimulatedJob[]>(() => (data.value ? simulationJobs(data.value, { send: send.value && props.ticket?.hasOrder === true, ticket: printTicket.value, mark: mark.value }) : []));
const printerName = (id: number | null) => data.value?.context.printers.find((p) => p.id === id)?.name ?? t('admin.tickets.simulation.noPrinter');

async function run(): Promise<void> {
    if (!props.ticket || !jobs.value.length || sending.value) {
        return;
    }

    sending.value = true;

    try {
        const response = await api<{ printers: string[] }>('POST', simulate.url(props.ticket.id), { device: deviceId.value, jobs: jobs.value });
        toast.success(t('admin.tickets.simulation.sent', { printers: response.printers.join(', ') || '—' }));
        emit('close');
    } catch {
        toast.error(t('common.error'));
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="ticket" class="max-h-[92vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Send class="size-5" /> {{ t('admin.tickets.simulation.title', { number: ticket.fullNumber }) }}</DialogTitle>
                <DialogDescription>{{ t('admin.tickets.simulation.help') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-1">
                <Label>{{ t('admin.tickets.simulation.from') }}</Label>
                <SelectInput v-model="deviceId" :options="deviceOptions" />
                <p class="text-xs text-muted-foreground">{{ t('admin.tickets.simulation.fromHelp') }}</p>
            </div>

            <div class="space-y-2">
                <Label class="flex items-center gap-2 font-normal" :class="{ 'opacity-50': !ticket.hasOrder }">
                    <Checkbox v-model="send" :disabled="!ticket.hasOrder" /> {{ t('admin.tickets.simulation.send') }}
                    <span v-if="!ticket.hasOrder" class="text-xs text-muted-foreground">({{ t('admin.tickets.simulation.noOrder') }})</span>
                </Label>
                <Label class="flex items-center gap-2 font-normal"><Checkbox v-model="printTicket" /> {{ t('admin.tickets.simulation.ticket') }}</Label>
                <Label class="flex items-center gap-2 font-normal"><Checkbox v-model="mark" /> {{ t('admin.tickets.simulation.markLabel') }}</Label>
            </div>

            <div class="rounded-lg border">
                <div class="border-b bg-muted/40 px-3 py-2 text-sm font-medium">{{ t('admin.tickets.simulation.whatPrints') }}</div>
                <div v-if="loading" class="flex items-center justify-center gap-2 p-6 text-sm text-muted-foreground"><Loader2 class="size-4 animate-spin" /> {{ t('common.loading') }}</div>
                <p v-else-if="!jobs.length" class="p-4 text-center text-sm text-muted-foreground">{{ t('admin.tickets.simulation.nothing') }}</p>
                <div v-else class="divide-y">
                    <div v-for="(job, index) in jobs" :key="index">
                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted/40" @click="shown = shown === index ? null : index">
                            <PrinterIcon class="size-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1 truncate">{{ job.title }}</span>
                            <span class="shrink-0 font-medium">→ {{ printerName(job.printerId) }}</span>
                        </button>
                        <div v-if="shown === index" class="flex justify-center bg-muted/20 p-3"><PrintPreview :document="job.document" /></div>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="emit('close')">{{ t('common.cancel') }}</Button>
                <Button :disabled="!jobs.length || loading || sending" @click="run">
                    <Send class="size-4" /> {{ sending ? t('cashier.sending') : t('admin.tickets.simulation.run') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
