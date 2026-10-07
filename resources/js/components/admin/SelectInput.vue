<script setup lang="ts" generic="T extends string | number | null">
const model = defineModel<T>();

defineProps<{
    options: { value: T; label: string; disabled?: boolean }[];
    placeholder?: string;
    id?: string;
}>();
</script>

<template>
    <select
        :id="id"
        v-model="model"
        class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30"
    >
        <option v-if="placeholder !== undefined" :value="null">
            {{ placeholder }}
        </option>
        <option
            v-for="option in options"
            :key="String(option.value)"
            :value="option.value"
            :disabled="option.disabled"
        >
            {{ option.label }}
        </option>
    </select>
</template>
