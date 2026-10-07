<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, watch } from 'vue';
import { toast } from 'vue-sonner';
import DeviceSetup from '@/components/pos/DeviceSetup.vue';
import PrintStation from '@/components/pos/PrintStation.vue';
import TopBar from '@/components/pos/TopBar.vue';
import AssistantView from '@/components/pos/views/AssistantView.vue';
import CashierView from '@/components/pos/views/CashierView.vue';
import ClockQrView from '@/components/pos/views/ClockQrView.vue';
import ClockView from '@/components/pos/views/ClockView.vue';
import FloorView from '@/components/pos/views/FloorView.vue';
import KdsView from '@/components/pos/views/KdsView.vue';
import OrderView from '@/components/pos/views/OrderView.vue';
import PaymentView from '@/components/pos/views/PaymentView.vue';
import ReservationsView from '@/components/pos/views/ReservationsView.vue';
import { Toaster } from '@/components/ui/sonner';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/i18n';
import { go, route } from '@/pos/router';
import { state } from '@/pos/store';
import { startSync, sync } from '@/pos/sync';
import type { DeviceInfo } from '@/pos/types';
import '@/pos/notifications';

const props = defineProps<{ device: DeviceInfo | null }>();

const device = computed(() => state.device ?? props.device);

function homeRoute(): string {
    if (state.me?.role === 'kitchen' || device.value?.type === 'kds') {
        return '/cuina';
    }

    if (device.value?.type === 'clock') {
        return '/qr';
    }

    if (device.value?.type === 'cashier') {
        return '/caixa';
    }

    return '/sala';
}

const view = computed(() => {
    switch (route.value.name) {
        case 'sala':
            return FloorView;
        case 'comanda':
        case 'taula':
            return OrderView;
        case 'caixa':
            return route.value.params[0] ? PaymentView : CashierView;
        case 'cuina':
            return KdsView;
        case 'reserves':
            return ReservationsView;
        case 'fitxar':
            return ClockView;
        case 'qr':
            return ClockQrView;
        case 'assistent':
            return AssistantView;
        default:
            return null;
    }
});

const fullscreen = computed(() => route.value.name === 'qr');

watch(
    () => [state.loaded, route.value.name] as const,
    ([loaded, name]) => {
        if (loaded && !name) {
            go(homeRoute());
        }
    },
    { immediate: true },
);

watch(
    () => sync.rejections.length,
    () => {
        while (sync.rejections.length) {
            const rejection = sync.rejections.shift();

            if (rejection) {
                toast.error(t('sync.rejected', { reason: t(`reasons.${rejection.reason}`) }));
            }
        }
    },
);

watch(
    () => sync.needsLogin,
    (needs) => {
        if (needs && navigator.onLine) {
            window.location.href = '/login';
        }
    },
);

onMounted(() => {
    if (device.value) {
        void startSync();
    }
});

function onRegistered(): void {
    void startSync();
}
</script>

<template>
    <Head title="TPV" />
    <Toaster position="top-center" rich-colors />

    <DeviceSetup v-if="!device" @registered="onRegistered" />

    <div v-else-if="!state.loaded" class="flex min-h-svh flex-col items-center justify-center gap-4 bg-[#00056a] text-white">
        <img src="/images/logo-centre-civic.png" alt="" class="h-24 brightness-0 invert" />
        <Spinner class="size-8" />
        <p v-if="!sync.online" class="max-w-xs text-center text-sm opacity-80">{{ t('sync.firstLoadNeedsNetwork') }}</p>
    </div>

    <div v-else class="flex h-svh flex-col overflow-hidden bg-slate-100 text-slate-900 select-none dark:bg-slate-950 dark:text-slate-100">
        <TopBar v-if="!fullscreen" />
        <main class="relative min-h-0 flex-1">
            <component :is="view" :key="route.name + (route.params[0] ?? '')" />
        </main>
        <PrintStation />
    </div>
</template>
