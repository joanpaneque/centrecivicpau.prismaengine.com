<script setup lang="ts">
import { t } from '@/i18n';

defineProps<{ allergens: string[]; small?: boolean; withLabels?: boolean }>();

const icons: Record<string, { emoji: string; color: string }> = {
    gluten: { emoji: '🌾', color: '#d97706' },
    crustaceans: { emoji: '🦐', color: '#0ea5e9' },
    eggs: { emoji: '🥚', color: '#eab308' },
    fish: { emoji: '🐟', color: '#2563eb' },
    peanuts: { emoji: '🥜', color: '#92400e' },
    soy: { emoji: '🫘', color: '#65a30d' },
    milk: { emoji: '🥛', color: '#64748b' },
    nuts: { emoji: '🌰', color: '#78350f' },
    celery: { emoji: '🥬', color: '#16a34a' },
    mustard: { emoji: '🟡', color: '#ca8a04' },
    sesame: { emoji: '⚪', color: '#a16207' },
    sulphites: { emoji: '🍷', color: '#9f1239' },
    lupin: { emoji: '🌼', color: '#c026d3' },
    molluscs: { emoji: '🐚', color: '#0891b2' },
};
</script>

<template>
    <span v-if="allergens.length" class="flex flex-wrap gap-1">
        <span
            v-for="allergen in allergens"
            :key="allergen"
            :title="t(`allergens.${allergen}`)"
            class="inline-flex items-center gap-1 rounded-full"
            :class="withLabels ? 'border px-2 py-0.5 text-sm' : ''"
            :style="withLabels ? { borderColor: icons[allergen]?.color } : {}"
        >
            <span :class="small ? 'text-[11px]' : 'text-base'">{{ icons[allergen]?.emoji ?? '•' }}</span>
            <span v-if="withLabels">{{ t(`allergens.${allergen}`) }}</span>
        </span>
    </span>
</template>
