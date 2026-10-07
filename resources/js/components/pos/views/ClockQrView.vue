<script setup lang="ts">
import { ArrowLeft } from '@lucide/vue';
import ClockQrPanel from '@/components/pos/ClockQrPanel.vue';
import { go } from '@/pos/router';
import { state } from '@/pos/store';

function back(): void {
    go(state.device?.type === 'cashier' ? '/caixa' : '/fitxar');
}
</script>

<template>
    <div class="relative h-full">
        <button
            v-if="state.device?.type !== 'clock'"
            type="button"
            class="absolute top-3 left-3 z-10 rounded-full p-2 text-white/40 hover:bg-white/10 hover:text-white"
            @click="back"
        >
            <ArrowLeft class="size-5" />
        </button>
        <ClockQrPanel />
    </div>
</template>
