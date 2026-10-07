<script setup lang="ts">
import { Delete } from '@lucide/vue';

const props = withDefaults(defineProps<{ length?: number; error?: boolean }>(), { length: 4, error: false });
const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ complete: [value: string] }>();

function press(digit: string): void {
    if (model.value.length >= props.length) {
        return;
    }

    model.value += digit;

    if (model.value.length === props.length) {
        emit('complete', model.value);
    }
}

function erase(): void {
    model.value = model.value.slice(0, -1);
}
</script>

<template>
    <div class="flex flex-col items-center gap-5">
        <div class="flex gap-3" :class="{ 'animate-[shake_0.3s]': error }">
            <span
                v-for="i in length"
                :key="i"
                class="size-4 rounded-full border-2 transition"
                :class="[i <= model.length ? 'border-[#00056a] bg-[#00056a]' : 'border-slate-300', error ? 'border-red-500 bg-red-500' : '']"
            />
        </div>
        <div class="grid grid-cols-3 gap-3">
            <button
                v-for="digit in ['1', '2', '3', '4', '5', '6', '7', '8', '9']"
                :key="digit"
                type="button"
                class="size-16 rounded-2xl bg-slate-100 text-2xl font-semibold active:bg-slate-300"
                @click="press(digit)"
            >
                {{ digit }}
            </button>
            <span />
            <button type="button" class="size-16 rounded-2xl bg-slate-100 text-2xl font-semibold active:bg-slate-300" @click="press('0')">0</button>
            <button type="button" class="flex size-16 items-center justify-center rounded-2xl text-slate-500 active:bg-slate-200" @click="erase">
                <Delete class="size-7" />
            </button>
        </div>
    </div>
</template>
