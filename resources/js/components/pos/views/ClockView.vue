<script setup lang="ts">
import { CalendarDays, Coffee, LogIn, LogOut, QrCode, ScanLine, ShieldCheck, X } from '@lucide/vue';
import QrScanner from 'qr-scanner';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { locale, t } from '@/i18n';
import { uuid } from '@/pos/crypto';
import { go, route } from '@/pos/router';
import { operator, operatorId, state } from '@/pos/store';
import { enqueue, sync } from '@/pos/sync';
import type { ClockType } from '@/pos/types';

const CODE = /^\d+\.[a-f0-9]{16}$/;

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;

const isMe = computed(() => operatorId() === state.me?.id);
const clock = computed(() => (isMe.value ? state.myClock : null));
const current = computed(() => clock.value?.state ?? 'out');
const fixedDevice = computed(() => state.device?.type === 'cashier' || state.device?.type === 'kds');

/** A code captured from the URL (phone camera opened the link) or the in-app scanner. */
const scanned = ref<{ code: string; at: number } | null>(null);
const scanning = ref(false);
const video = ref<HTMLVideoElement | null>(null);
let scanner: QrScanner | null = null;
const busy = ref(false);
const pendingAction = ref<ClockType | null>(null);

const actions = computed<{ type: ClockType; label: string; icon: typeof LogIn; class: string }[]>(() => {
    const all = {
        clock_in: { type: 'clock_in' as const, label: t('clock.clockIn'), icon: LogIn, class: 'bg-emerald-600 hover:bg-emerald-700' },
        clock_out: { type: 'clock_out' as const, label: t('clock.clockOut'), icon: LogOut, class: 'bg-red-600 hover:bg-red-700' },
        break_start: { type: 'break_start' as const, label: t('clock.breakStart'), icon: Coffee, class: 'bg-amber-500 hover:bg-amber-600' },
        break_end: { type: 'break_end' as const, label: t('clock.breakEnd'), icon: LogIn, class: 'bg-emerald-600 hover:bg-emerald-700' },
    };

    if (!isMe.value) {
        return Object.values(all);
    }

    if (current.value === 'out') {
        return [all.clock_in];
    }

    if (current.value === 'break') {
        return [all.break_end, all.clock_out];
    }

    return [all.break_start, all.clock_out];
});

const workedMinutes = computed(() => {
    const entries = [...(clock.value?.entries ?? [])].sort((a, b) => a.at.localeCompare(b.at));

    if (!entries.length) {
        return clock.value?.todayMinutes ?? 0;
    }

    let total = 0;
    let start: number | null = null;

    for (const entry of entries) {
        const at = new Date(entry.at).getTime();

        if (entry.type === 'clock_in' || entry.type === 'break_end') {
            start ??= at;
        } else if (start !== null) {
            total += at - start;
            start = null;
        }
    }

    if (start !== null) {
        total += now.value - start;
    }

    return Math.max(clock.value?.todayMinutes ?? 0, Math.floor(total / 60000));
});

const upcomingShifts = computed(() => {
    const today = new Date();
    const iso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

    return state.myShifts.filter((s) => s.date >= iso).slice(0, 7);
});

function hm(minutes: number): string {
    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function shiftDay(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString(locale.value === 'es' ? 'es-ES' : 'ca-ES', { weekday: 'short', day: 'numeric', month: 'short' });
}

function typeLabel(type: ClockType): string {
    return { clock_in: t('clock.clockIn'), clock_out: t('clock.clockOut'), break_start: t('clock.breakStart'), break_end: t('clock.breakEnd') }[type];
}

function extractCode(data: string): string | null {
    const value = data.trim();

    if (CODE.test(value)) {
        return value;
    }

    const match = value.match(/#\/fitxar\/([^/?#\s]+)/);

    return match && CODE.test(match[1]) ? match[1] : null;
}

function freshCode(): string | null {
    // The server accepts a code within one rotation window of the clock-in time.
    const seconds = state.settings?.clock_qr_seconds ?? 45;

    return scanned.value && Date.now() - scanned.value.at < seconds * 1000 ? scanned.value.code : null;
}

async function startScanner(): Promise<void> {
    scanning.value = true;
    await nextTick();

    if (!video.value) {
        return;
    }

    try {
        scanner = new QrScanner(
            video.value,
            (result) => {
                const code = extractCode(result.data);

                if (code) {
                    scanned.value = { code, at: Date.now() };
                    stopScanner();
                    toast.success(t('clock.qrOk'));

                    if (pendingAction.value) {
                        const type = pendingAction.value;
                        pendingAction.value = null;
                        void record(type);
                    }
                }
            },
            { returnDetailedScanResult: true, highlightScanRegion: true, preferredCamera: 'environment' },
        );
        await scanner.start();
    } catch {
        toast.error(t('clock.cameraError'));
        stopScanner();
    }
}

function cancelScanner(): void {
    pendingAction.value = null;
    stopScanner();
}

function stopScanner(): void {
    scanner?.stop();
    scanner?.destroy();
    scanner = null;
    scanning.value = false;
}

async function record(type: ClockType, withoutQr = false): Promise<void> {
    const code = freshCode();

    if (!code && !withoutQr) {
        pendingAction.value = type;
        await startScanner();

        return;
    }

    busy.value = true;

    try {
        await enqueue('time.clock', {
            type,
            occurredAt: new Date(code ? (scanned.value?.at ?? Date.now()) : Date.now()).toISOString(),
            source: code ? 'qr' : 'app',
            qrCode: code,
            entryUuid: uuid(),
        });
        scanned.value = null;
        toast.success(sync.online ? t('clock.recorded') : t('clock.recordedOffline'));

        if (route.value.params[0]) {
            go('/fitxar');
        }
    } finally {
        busy.value = false;
    }
}

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 30000);
    const fromUrl = route.value.params[0] ? extractCode(decodeURIComponent(route.value.params[0])) : null;

    if (fromUrl) {
        scanned.value = { code: fromUrl, at: Date.now() };
        toast.success(t('clock.qrOk'));
    } else if (route.value.params[0]) {
        toast.error(t('clock.qrInvalid'));
    }
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }

    stopScanner();
});
</script>

<template>
    <div class="h-full overflow-y-auto">
        <div class="mx-auto grid max-w-4xl gap-4 p-4 md:grid-cols-[1fr_300px]">
            <section class="rounded-2xl bg-white p-6 shadow-sm dark:bg-slate-900">
                <p class="text-sm text-slate-500">{{ operator()?.name }}</p>
                <div class="mt-1 flex items-center gap-3">
                    <span
                        class="size-4 rounded-full"
                        :class="current === 'in' ? 'bg-emerald-500' : current === 'break' ? 'bg-amber-500' : 'bg-slate-400'"
                    />
                    <h2 class="text-2xl font-bold">{{ isMe ? t(`clock.status.${current}`) : t('clock.asOperator') }}</h2>
                </div>
                <p v-if="isMe && clock?.since && current !== 'out'" class="text-slate-500">{{ t('clock.since', { time: time(clock.since) }) }}</p>
                <p v-if="isMe" class="mt-2 text-lg font-medium tabular-nums">{{ t('clock.todayWorked', { time: hm(workedMinutes) }) }}</p>

                <div v-if="scanned && freshCode()" class="mt-4 flex items-center gap-2 rounded-lg bg-emerald-50 p-3 text-emerald-800">
                    <ShieldCheck class="size-5" /> {{ t('clock.qrOk') }}
                </div>

                <div v-if="scanning" class="relative mt-4 overflow-hidden rounded-xl bg-black">
                    <video ref="video" class="aspect-square w-full object-cover" muted playsinline />
                    <p class="absolute inset-x-0 bottom-0 bg-black/60 p-2 text-center text-sm text-white">{{ t('clock.scanning') }}</p>
                    <Button variant="secondary" size="icon" class="absolute top-2 right-2" @click="cancelScanner"><X class="size-5" /></Button>
                </div>

                <div class="mt-6 grid gap-3" :class="actions.length > 2 ? 'grid-cols-2' : actions.length === 2 ? 'grid-cols-2' : 'grid-cols-1'">
                    <Button v-for="action in actions" :key="action.type" class="h-20 text-xl text-white" :class="action.class" :disabled="busy" @click="record(action.type)">
                        <component :is="action.icon" class="size-7" />
                        {{ action.label }}
                        <QrCode v-if="!freshCode()" class="size-5 opacity-70" />
                    </Button>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <Button v-if="!scanning" variant="outline" class="h-11" @click="startScanner"><ScanLine class="size-4" /> {{ t('clock.scanQr') }}</Button>
                    <template v-if="fixedDevice">
                        <Button v-for="action in actions" :key="`noqr-${action.type}`" variant="ghost" class="h-11 text-slate-500" :disabled="busy" @click="record(action.type, true)">
                            {{ t('clock.withoutQr', { action: action.label }) }}
                        </Button>
                    </template>
                </div>

                <p class="mt-6 text-xs text-slate-500">{{ t('clock.legal') }}</p>
            </section>

            <aside class="space-y-4">
                <div v-if="isMe" class="rounded-2xl bg-white p-4 shadow-sm dark:bg-slate-900">
                    <h3 class="mb-2 font-semibold">{{ t('clock.history') }}</h3>
                    <p v-if="!clock?.entries.length" class="text-sm text-slate-500">{{ t('common.empty') }}</p>
                    <ul class="space-y-1 text-sm">
                        <li v-for="entry in clock?.entries ?? []" :key="entry.at + entry.type" class="flex items-center justify-between">
                            <span>{{ typeLabel(entry.type) }}</span>
                            <span class="flex items-center gap-1 tabular-nums">
                                {{ time(entry.at) }}
                                <span v-if="entry.pending" class="rounded bg-amber-100 px-1 text-[10px] text-amber-800">{{ t('clock.pendingSync') }}</span>
                                <span v-if="entry.corrected" class="rounded bg-blue-100 px-1 text-[10px] text-blue-800">{{ t('clock.corrected') }}</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <div v-if="isMe" class="rounded-2xl bg-white p-4 shadow-sm dark:bg-slate-900">
                    <h3 class="mb-2 flex items-center gap-2 font-semibold"><CalendarDays class="size-4" /> {{ t('clock.myShifts') }}</h3>
                    <p v-if="!upcomingShifts.length" class="text-sm text-slate-500">{{ t('clock.noShifts') }}</p>
                    <ul class="space-y-2 text-sm">
                        <li v-for="shift in upcomingShifts" :key="shift.id" class="flex items-center gap-2">
                            <span class="h-8 w-1.5 rounded" :style="{ backgroundColor: shift.color }" />
                            <span class="flex-1">
                                <span class="block font-medium capitalize">{{ shiftDay(shift.date) }}</span>
                                <span class="text-xs text-slate-500">{{ shift.name ?? '' }}</span>
                            </span>
                            <span class="tabular-nums">{{ shift.startTime }}–{{ shift.endTime }}</span>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</template>
