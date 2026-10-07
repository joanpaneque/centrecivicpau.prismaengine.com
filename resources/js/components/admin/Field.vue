<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

defineProps<{
    label?: string;
    for?: string;
    error?: string;
    help?: string;
}>();
</script>

<template>
    <div class="grid gap-1.5">
        <Label v-if="label" :for="$props.for">{{ label }}</Label>
        <slot />
        <p v-if="help" class="text-xs text-muted-foreground">{{ help }}</p>
        <InputError :message="error" />
    </div>
</template>
