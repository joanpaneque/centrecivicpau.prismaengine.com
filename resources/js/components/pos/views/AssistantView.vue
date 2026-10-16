<script setup lang="ts">
import { computed } from 'vue';
import AssistantChat from '@/components/assistant/AssistantChat.vue';
import MenuStudio from '@/components/assistant/MenuStudio.vue';
import { operator, state } from '@/pos/store';

/** Administrators get the assistant that also manages the carta and menus; staff, the help chat. */
const current = computed(() => operator() as { name?: string; role?: string } | null);
const userName = computed(() => current.value?.name ?? state.me?.name ?? null);
const isAdmin = computed(() => (current.value?.role ?? state.me?.role) === 'admin');
</script>

<template>
    <div class="absolute inset-0 bg-background">
        <MenuStudio v-if="isAdmin" context="tpv" class="h-full" :user-name="userName" />
        <AssistantChat v-else context="tpv" class="h-full rounded-none border-0" :user-name="userName" />
    </div>
</template>
