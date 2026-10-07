<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import Pagination from '@/components/admin/Pagination.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { audit as auditRoute } from '@/routes/admin';

type Log = {
    id: number;
    action: string;
    user: string | null;
    device: string | null;
    subject: string | null;
    data: Record<string, unknown> | null;
    reason: string | null;
    createdAt: string;
};

const props = defineProps<{
    logs: { data: Log[]; links: { url: string | null; label: string; active: boolean }[] };
    actions: string[];
    users: { id: number; name: string }[];
    filters: { action?: string; user?: number; from?: string; to?: string };
}>();

const { t } = useI18n();
const filters = reactive({
    action: (props.filters.action ?? null) as string | null,
    user: (props.filters.user ? Number(props.filters.user) : null) as number | null,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

watch(filters, () => {
    router.get(
        auditRoute.url(),
        { action: filters.action || undefined, user: filters.user || undefined, from: filters.from || undefined, to: filters.to || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

function summarize(data: Record<string, unknown> | null): string {
    if (!data) {
        return '';
    }

    return Object.entries(data)
        .map(([key, value]) => `${key}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`)
        .join(' · ');
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Auditoria', href: auditRoute() }] } });
</script>

<template>
    <Head :title="t('admin.audit.title')" />
    <PageHeader :title="t('admin.audit.title')" :description="t('admin.audit.description')" />

    <div class="mb-4 flex flex-wrap gap-2">
        <SelectInput v-model="filters.action" class="max-w-56" :placeholder="t('common.all')" :options="actions.map((a) => ({ value: a as string | null, label: a }))" />
        <SelectInput v-model="filters.user" class="max-w-48" :placeholder="t('admin.audit.user')" :options="users.map((u) => ({ value: u.id as number | null, label: u.name }))" />
        <Input v-model="filters.from" type="date" class="max-w-40" />
        <Input v-model="filters.to" type="date" class="max-w-40" />
    </div>

    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full text-left text-sm">
            <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                <tr>
                    <th class="px-3 py-2">{{ t('common.date') }}</th>
                    <th class="px-3 py-2">{{ t('admin.audit.action') }}</th>
                    <th class="px-3 py-2">{{ t('admin.audit.user') }}</th>
                    <th class="px-3 py-2">{{ t('admin.audit.subject') }}</th>
                    <th class="px-3 py-2">{{ t('admin.audit.data') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="log in logs.data" :key="log.id" class="border-t align-top">
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(log.createdAt) }}</td>
                    <td class="px-3 py-2"><Badge variant="secondary">{{ log.action }}</Badge></td>
                    <td class="px-3 py-2">
                        {{ log.user ?? '—' }}
                        <div v-if="log.device" class="text-xs text-muted-foreground">{{ log.device }}</div>
                    </td>
                    <td class="px-3 py-2 text-xs">{{ log.subject ?? '—' }}</td>
                    <td class="max-w-md px-3 py-2 text-xs break-words text-muted-foreground">
                        <div v-if="log.reason" class="text-foreground">«{{ log.reason }}»</div>
                        {{ summarize(log.data) }}
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="logs.data.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
    </div>
    <Pagination :links="logs.links" />
</template>
