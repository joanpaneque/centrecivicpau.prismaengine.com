<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
}>();
</script>

<template>
    <nav v-if="links.length > 3" class="mt-4 flex flex-wrap justify-center gap-1">
        <template v-for="(link, index) in links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="min-w-9 rounded-md border px-3 py-1.5 text-center text-sm"
                :class="link.active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
            >
                <span v-html="link.label" />
            </Link>
            <span v-else class="min-w-9 rounded-md px-3 py-1.5 text-center text-sm text-muted-foreground" v-html="link.label" />
        </template>
    </nav>
</template>
