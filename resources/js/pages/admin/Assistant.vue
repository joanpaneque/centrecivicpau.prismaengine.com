<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MenuStudio from '@/components/assistant/MenuStudio.vue';
import { useI18n } from '@/i18n';

const { t } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Assistent IA', href: '/gestion/asistente' }],
    },
});
</script>

<template>
    <Head :title="t('studio.title')" />

    <div class="-mx-4 -my-6 flex h-[calc(100svh-4rem)] min-h-[32rem] flex-col">
        <MenuStudio context="admin" class="min-h-0 flex-1" />
    </div>
</template>
