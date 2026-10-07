<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import type { PendingPrintJob } from '@/pos/printQueue';
import { markPrintJobDone, printQueue } from '@/pos/printQueue';
import { state } from '@/pos/store';
import PrintPreview from './PrintPreview.vue';

const ticketEl = ref<HTMLElement | null>(null);
const current = ref<PendingPrintJob | null>(null);
const busy = ref(false);
const waitingDialog = ref(false);

let running = false;
let cancelled = false;
let generation = 0;

const remaining = computed(() => printQueue.jobs.length);
const targetName = computed(() => current.value?.systemName || current.value?.printerName || '');
const paper58 = computed(() => (current.value?.paperWidth ?? current.value?.document.width ?? 48) <= 32);

const isStation = computed(() => state.device?.type === 'cashier');

watch(
    () => [isStation.value, printQueue.jobs.map((job) => job.uuid).join('|')] as const,
    () => {
        if (isStation.value) {
            void pump();
        }
    },
    { immediate: true },
);

onUnmounted(() => {
    cancelled = true;
    document.documentElement.classList.remove('tpv-printing');
});

async function pump(): Promise<void> {
    if (!isStation.value || running) {
        return;
    }

    running = true;

    try {
        while (!cancelled && printQueue.jobs.length > 0) {
            const job = printQueue.jobs[0];

            if (!job) {
                break;
            }

            if (!(await process(job))) {
                break;
            }
        }
    } finally {
        running = false;

        if (printQueue.jobs.length === 0) {
            current.value = null;
        }
    }
}

async function process(job: PendingPrintJob): Promise<boolean> {
    const mine = ++generation;
    current.value = job;
    busy.value = true;

    try {
        await api('POST', `/tpv/api/print-jobs/${job.uuid}/claim`);
    } catch (error) {
        busy.value = false;

        if (error instanceof HttpError && error.status === 409) {
            drop(job.uuid);
        } else {
            printQueue.jobs = printQueue.jobs.filter((item) => item.uuid !== job.uuid);
        }

        return true;
    }

    await nextTick();
    await waitImages();
    await delay(500);

    if (cancelled || mine !== generation || current.value?.uuid !== job.uuid) {
        busy.value = false;

        return printQueue.jobs.length > 0;
    }

    const result = await printNow();
    busy.value = false;

    if (mine !== generation) {
        return printQueue.jobs.length > 0;
    }

    if (result === 'printed') {
        await finish(job.uuid, 'printed');

        return true;
    }

    return false;
}

async function printNow(): Promise<'printed' | 'timeout'> {
    waitingDialog.value = true;
    document.documentElement.classList.add('tpv-printing');

    const outcome = await new Promise<'printed' | 'timeout'>((resolve) => {
        let settled = false;
        const done = (value: 'printed' | 'timeout') => {
            if (settled) {
                return;
            }

            settled = true;
            window.removeEventListener('afterprint', onAfter);
            window.clearTimeout(timer);
            resolve(value);
        };
        const onAfter = () => done('printed');
        window.addEventListener('afterprint', onAfter);
        const timer = window.setTimeout(() => done('timeout'), 180000);
        window.print();
    });

    document.documentElement.classList.remove('tpv-printing');
    waitingDialog.value = false;

    return outcome;
}

async function finish(uuid: string, status: 'printed' | 'failed'): Promise<void> {
    try {
        await api('POST', `/tpv/api/print-jobs/${uuid}/ack`, { status });
    } catch {
        // The next pull will retry or drop the job.
    }

    drop(uuid);

    if (current.value?.uuid === uuid) {
        current.value = null;
    }
}

function drop(uuid: string): void {
    markPrintJobDone(uuid);
}

async function reprint(): Promise<void> {
    if (!current.value || busy.value) {
        return;
    }

    busy.value = true;
    await waitImages();
    const result = await printNow();
    busy.value = false;

    if (result === 'printed' && current.value) {
        await finish(current.value.uuid, 'printed');
        void pump();
    }
}

async function skip(): Promise<void> {
    if (!current.value) {
        return;
    }

    generation++;
    await finish(current.value.uuid, 'failed');
    void pump();
}

async function waitImages(): Promise<void> {
    const root = ticketEl.value;

    if (!root) {
        return;
    }

    const images = [...root.querySelectorAll('img')];

    await Promise.all(
        images.map(
            (image) =>
                image.complete
                    ? Promise.resolve()
                    : new Promise<void>((resolve) => {
                          image.addEventListener('load', () => resolve(), { once: true });
                          image.addEventListener('error', () => resolve(), { once: true });
                      }),
        ),
    );
}

function delay(ms: number): Promise<void> {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}
</script>

<template>
    <div v-if="isStation && current" class="pointer-events-none fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center print:hidden">
        <div class="absolute inset-0 bg-black/50" />
        <div class="pointer-events-auto relative w-full max-w-md rounded-2xl bg-white p-4 text-slate-900 shadow-xl dark:bg-slate-900 dark:text-slate-100">
            <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">{{ t('printStation.title') }} · {{ t('printStation.queue', { current: 1, total: remaining }) }}</p>
            <h2 class="mt-1 text-2xl font-semibold leading-tight">{{ t('printStation.target', { name: targetName }) }}</h2>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ t('printStation.hint') }}</p>
            <p class="mt-1 text-sm font-medium">{{ current.title }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <Button size="lg" :disabled="busy || waitingDialog" @click="reprint">{{ t('printStation.print') }}</Button>
                <Button size="lg" variant="ghost" :disabled="waitingDialog" @click="skip">{{ t('printStation.skip') }}</Button>
            </div>
        </div>
    </div>

    <div
        v-if="current"
        id="tpv-print-ticket"
        ref="ticketEl"
        class="pointer-events-none fixed top-0 left-[-200vw] print:relative print:left-0"
        :class="paper58 ? 'paper-58' : ''"
    >
        <PrintPreview :document="current.document" plain />
    </div>
</template>

<style>
@media print {
    @page {
        size: 80mm auto;
        margin: 3mm;
    }

    html.tpv-printing body * {
        visibility: hidden !important;
    }

    html.tpv-printing #tpv-print-ticket,
    html.tpv-printing #tpv-print-ticket * {
        visibility: visible !important;
    }

    html.tpv-printing #tpv-print-ticket {
        position: absolute !important;
        inset: 0 auto auto 0 !important;
        width: 80mm !important;
        background: white !important;
    }

    html.tpv-printing #tpv-print-ticket.paper-58 {
        width: 58mm !important;
    }
}
</style>
