<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import MenuStudio from '@/components/assistant/MenuStudio.vue';
import { useI18n } from '@/i18n';
import type { Auth } from '@/types';

const { t } = useI18n();
const page = usePage<{ auth: Auth }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carta amb IA', href: '/gestion/carta-ia' }],
    },
});
</script>

<template>
    <Head :title="t('studio.title')" />

    <div class="-mx-4 -my-6 flex h-[calc(100svh-4rem)] min-h-[32rem] flex-col">
        <MenuStudio context="admin" class="min-h-0 flex-1" :user-name="page.props.auth.user?.name" />
    </div>
</template>
