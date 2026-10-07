<script setup lang="ts">
import { computed } from 'vue';
import AssistantChat from '@/components/assistant/AssistantChat.vue';
import { operator, state } from '@/pos/store';

const userName = computed(() => operator()?.name ?? state.me?.name ?? null);
</script>

<template>
    <div class="absolute inset-0 bg-background">
        <AssistantChat context="tpv" class="h-full rounded-none border-0" :user-name="userName" />
    </div>
</template>
