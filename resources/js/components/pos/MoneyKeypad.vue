<script setup lang="ts">
import { Delete } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { formatMoney, parseMoney } from '@/pos/money';

const props = withDefaults(
    defineProps<{
        title?: string;
        minCents?: number;
    }>(),
    { minCents: 1 },
);

const emit = defineEmits<{
    confirm: [cents: number];
    cancel: [];
}>();

const digits = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
const raw = defineModel<string>({ default: '' });

const cents = computed(() => parseMoney(raw.value));
const tooSmall = computed(() => cents.value < props.minCents);
const canConfirm = computed(() => cents.value > 0 && !tooSmall.value);

function press(token: string): void {
    if (token === ',') {
        if (raw.value.includes(',')) {
            return;
        }

        raw.value = `${raw.value || '0'},`;

        return;
    }

    const comma = raw.value.indexOf(',');

    if (comma >= 0 && raw.value.length - comma > 2) {
        return;
    }

    if (!raw.value.includes(',') && raw.value.replace(/^0+/, '').length >= 7 && raw.value !== '0') {
        return;
    }

    if (raw.value === '0' && token !== '0') {
        raw.value = token;

        return;
    }

    if (raw.value === '0' && token === '0') {
        return;
    }

    raw.value += token;
}

function erase(): void {
    raw.value = raw.value.slice(0, -1);
}

function confirm(): void {
    if (!canConfirm.value) {
        return;
    }

    emit('confirm', cents.value);
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-3 sm:items-center" @click.self="emit('cancel')">
        <div class="w-full max-w-md rounded-3xl bg-white p-4 text-slate-900 shadow-2xl dark:bg-slate-900 dark:text-slate-100">
            <p class="text-center text-sm font-medium text-slate-500">{{ title || t('cashier.paidMore') }}</p>
            <p class="mt-2 mb-4 min-h-16 text-center text-5xl font-bold tabular-nums tracking-tight">
                {{ raw ? `${raw} €` : formatMoney(0) }}
            </p>
            <p v-if="tooSmall && cents > 0" class="mb-3 text-center text-sm text-red-600">{{ t('cashier.paidMoreMin', { amount: formatMoney(minCents) }) }}</p>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="digit in digits"
                    :key="digit"
                    type="button"
                    class="h-16 rounded-2xl bg-slate-100 text-3xl font-semibold active:bg-slate-300 dark:bg-slate-800"
                    @click="press(digit)"
                >
                    {{ digit }}
                </button>
                <button type="button" class="h-16 rounded-2xl bg-slate-100 text-3xl font-semibold active:bg-slate-300 dark:bg-slate-800" @click="press(',')">,</button>
                <button type="button" class="h-16 rounded-2xl bg-slate-100 text-3xl font-semibold active:bg-slate-300 dark:bg-slate-800" @click="press('0')">0</button>
                <button type="button" class="flex h-16 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 active:bg-slate-300 dark:bg-slate-800" @click="erase">
                    <Delete class="size-8" />
                </button>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <Button variant="outline" class="h-14 text-lg" @click="emit('cancel')">{{ t('common.cancel') }}</Button>
                <Button class="h-14 bg-[#00056a] text-lg" :disabled="!canConfirm" @click="confirm">{{ t('common.confirm') }}</Button>
            </div>
        </div>
    </div>
</template>
