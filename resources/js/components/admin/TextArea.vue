<script setup lang="ts">
const model = defineModel<string | null>();

defineProps<{
    id?: string;
    rows?: number;
    placeholder?: string;
}>();
</script>

<template>
    <textarea
        :id="id"
        v-model="model"
        :rows="rows ?? 3"
        :placeholder="placeholder"
        class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
    />
</template>
