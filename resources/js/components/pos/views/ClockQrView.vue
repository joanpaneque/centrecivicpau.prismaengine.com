<script setup lang="ts">
import { ArrowLeft, WifiOff } from '@lucide/vue';
import QRCode from 'qrcode';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { locale, t } from '@/i18n';
import { clockQrCode } from '@/pos/crypto';
import { go } from '@/pos/router';
import { state } from '@/pos/store';
import { sync } from '@/pos/sync';

const now = ref(Date.now());
const image = ref<string | null>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const seconds = computed(() => state.clock?.seconds ?? 45);
const windowIndex = computed(() => Math.floor(now.value / 1000 / seconds.value));
const remaining = computed(() => seconds.value - Math.floor((now.value / 1000) % seconds.value));
const code = computed(() => (state.clock ? clockQrCode(state.clock.secret, seconds.value, windowIndex.value * seconds.value * 1000) : null));
const url = computed(() => (state.clock && code.value ? `${state.clock.url}/${code.value}` : null));

const clockText = computed(() => new Date(now.value).toLocaleTimeString(locale.value === 'es' ? 'es-ES' : 'ca-ES', { hour: '2-digit', minute: '2-digit' }));
const dateText = computed(() => new Date(now.value).toLocaleDateString(locale.value === 'es' ? 'es-ES' : 'ca-ES', { weekday: 'long', day: 'numeric', month: 'long' }));

watch(
    url,
    async (value) => {
        image.value = value ? await QRCode.toDataURL(value, { margin: 1, width: 640, errorCorrectionLevel: 'M' }) : null;
    },
    { immediate: true },
);

onMounted(() => {
    timer = setInterval(() => (now.value = Date.now()), 1000);
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }
});
</script>

<template>
    <div class="flex h-full flex-col items-center justify-center gap-6 bg-[#00056a] p-6 text-white">
        <button type="button" class="absolute top-3 left-3 rounded-full p-2 text-white/40 hover:bg-white/10 hover:text-white" @click="go('/fitxar')">
            <ArrowLeft class="size-5" />
        </button>

        <img src="/images/logo-centre-civic.png" alt="" class="h-16 brightness-0 invert" />

        <template v-if="state.clock && image">
            <h1 class="text-3xl font-bold">{{ t('clock.qrScreenTitle') }}</h1>
            <div class="rounded-3xl bg-white p-4 shadow-2xl">
                <img :src="image" alt="QR" class="size-[min(60vh,70vw)]" />
            </div>
            <div class="h-2 w-[min(60vh,70vw)] overflow-hidden rounded-full bg-white/20">
                <div class="h-full bg-white transition-all duration-1000 ease-linear" :style="{ width: `${(remaining / seconds) * 100}%` }" />
            </div>
            <p class="max-w-lg text-center text-lg opacity-90">{{ t('clock.qrScreenHelp') }}</p>
            <p class="text-sm opacity-70">{{ t('clock.qrRefresh', { seconds }) }}</p>
        </template>
        <p v-else class="max-w-md text-center text-lg">{{ t('clock.notClockDevice') }}</p>

        <div class="text-center">
            <p class="text-5xl font-bold tabular-nums">{{ clockText }}</p>
            <p class="capitalize opacity-80">{{ dateText }}</p>
        </div>

        <p v-if="!sync.online" class="flex items-center gap-2 rounded-full bg-amber-500/90 px-4 py-1 text-sm"><WifiOff class="size-4" /> {{ t('sync.offline') }}</p>
    </div>
</template>
