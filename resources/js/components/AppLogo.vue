<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard } from '@/routes';

withDefaults(
    defineProps<{
        /** Solo el icono (aside contraído). */
        iconOnly?: boolean;
    }>(),
    {
        iconOnly: false,
    },
);
</script>

<template>
    <Link
        v-if="iconOnly"
        :href="dashboard()"
        class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center"
        aria-label="Centre Cívic Pau"
    >
        <AppLogoIcon class="size-6" />
    </Link>
    <Link
        v-else
        :href="dashboard()"
        class="inline-flex w-fit max-w-full cursor-pointer"
        aria-label="Centre Cívic Pau"
    >
        <img
            src="/images/logo-centre-civic.png"
            alt="Centre Cívic Pau"
            class="h-10 w-auto"
        />
    </Link>
</template>
