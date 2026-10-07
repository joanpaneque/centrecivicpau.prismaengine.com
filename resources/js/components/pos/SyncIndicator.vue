<script setup lang="ts">
import { CloudOff, Loader2, RefreshCw, Wifi } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/i18n';
import { pull, sync } from '@/pos/sync';

const label = computed(() => {
    if (!sync.online) {
        return sync.pending ? `${t('sync.offline')} · ${t('sync.pending', { count: sync.pending })}` : t('sync.offline');
    }

    if (sync.pending) {
        return t('sync.pending', { count: sync.pending });
    }

    return t('sync.online');
});

const title = computed(() => {
    const parts = [label.value];

    if (sync.lastSyncAt) {
        parts.push(t('sync.lastSync', { time: new Date(sync.lastSyncAt).toLocaleTimeString() }));
    }

    if (!sync.online) {
        parts.push(t('sync.offlineBanner'));
    }

    return parts.join('\n');
});
</script>

<template>
    <button
        type="button"
        class="flex h-10 shrink-0 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold"
        :class="!sync.online ? 'bg-amber-400 text-amber-950' : sync.pending ? 'bg-sky-400/30 text-white' : 'text-emerald-300'"
        :title="title"
        @click="pull()"
    >
        <CloudOff v-if="!sync.online" class="size-4" />
        <Loader2 v-else-if="sync.syncing" class="size-4 animate-spin" />
        <RefreshCw v-else-if="sync.pending" class="size-4" />
        <Wifi v-else class="size-4" />
        <span class="hidden sm:inline">{{ label }}</span>
    </button>
</template>
