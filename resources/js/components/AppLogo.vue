<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrismaEngineLogo from '@/components/PrismaEngineLogo.vue';
import PyramidCanvas from '@/components/PyramidCanvas.vue';
import { dashboard } from '@/routes';

withDefaults(
    defineProps<{
        size?: number;
        product?: string;
        compact?: boolean;
        /** Solo el canvas de la pirámide (aside contraído). */
        iconOnly?: boolean;
    }>(),
    {
        size: 28 / 150,
        product: 'Engine',
        compact: false,
        iconOnly: false,
    },
);

const pyramidHovered = ref(false);
</script>

<template>
    <Link
        v-if="iconOnly"
        :href="dashboard()"
        class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center overflow-hidden"
        aria-label="Prisma Engine"
        @mouseenter="pyramidHovered = true"
        @mouseleave="pyramidHovered = false"
    >
        <PyramidCanvas
            :stroke-width="34"
            stroke-color="#0040c1"
            :show-controls="false"
            :offset-y-px="5"
            :spin-when-hovered="true"
            :is-hovered="pyramidHovered"
            :idle-rotation-y-deg="45"
            :spin-out-ms="2200"
            class="size-6"
        />
    </Link>
    <Link
        v-else
        :href="dashboard()"
        class="inline-flex w-fit max-w-full cursor-pointer"
        aria-label="Prisma Engine"
    >
        <PrismaEngineLogo
            :size="size"
            :product="product"
            :compact="compact"
            accent-color="#0040c1"
        />
    </Link>
</template>
