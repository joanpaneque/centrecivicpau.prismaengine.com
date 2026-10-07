<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type TransValue = { ca: string; es: string };

const model = defineModel<TransValue>({ required: true });

defineProps<{
    label: string;
    error?: string;
    multiline?: boolean;
}>();

function update(lang: 'ca' | 'es', value: string | number): void {
    model.value = { ...model.value, [lang]: String(value) };
}
</script>

<template>
    <div class="grid gap-1.5">
        <Label>{{ label }}</Label>
        <div class="grid gap-2 sm:grid-cols-2">
            <div v-for="lang in ['ca', 'es'] as const" :key="lang" class="relative">
                <span
                    class="pointer-events-none absolute top-2 left-2 rounded bg-muted px-1 text-[10px] font-semibold text-muted-foreground uppercase"
                    >{{ lang }}</span
                >
                <textarea
                    v-if="multiline"
                    :value="model[lang]"
                    rows="2"
                    class="w-full rounded-md border border-input bg-transparent py-2 pr-3 pl-10 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    @input="update(lang, ($event.target as HTMLTextAreaElement).value)"
                />
                <Input
                    v-else
                    :model-value="model[lang]"
                    class="pl-10"
                    @update:model-value="update(lang, $event)"
                />
            </div>
        </div>
        <InputError :message="error" />
    </div>
</template>
