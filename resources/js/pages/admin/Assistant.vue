<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AssistantChat from '@/components/assistant/AssistantChat.vue';
import { useI18n } from '@/i18n';
import type { Auth } from '@/types';

const { t } = useI18n();
const page = usePage<{ auth: Auth }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Assistent IA', href: '/gestion/asistente' }],
    },
});
</script>

<template>
    <Head :title="t('assistant.title')" />

    <div class="-mx-4 -my-6 flex h-[calc(100svh-4rem)] min-h-[32rem] flex-col">
        <AssistantChat context="admin" class="min-h-0 flex-1 rounded-none border-0" :user-name="page.props.auth.user?.name" />
    </div>
</template>
