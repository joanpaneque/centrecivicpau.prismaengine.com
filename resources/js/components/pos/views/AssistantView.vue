<script setup lang="ts">
import { computed } from 'vue';
import type { ChatMessage } from '@/assistant/api';
import MenuStudio from '@/components/assistant/MenuStudio.vue';
import AssistantOrderCard from '@/components/pos/AssistantOrderCard.vue';
import { applyAssistantOrder } from '@/pos/assistantOrders';
import { operator, state } from '@/pos/store';

/**
 * The assistant on the POS, for everyone: it answers questions and takes orders by voice;
 * administrators also manage the carta and menus and see reports. Orders dictated here are
 * added to the table on this tablet as soon as the reply arrives.
 */
const current = computed(() => operator() as { name?: string; role?: string } | null);
const userName = computed(() => current.value?.name ?? state.me?.name ?? null);
const isAdmin = computed(() => (current.value?.role ?? state.me?.role) === 'admin');

async function onReply(message: ChatMessage): Promise<void> {
    for (const preview of message.meta?.previews ?? []) {
        if (preview.type === 'order') {
            await applyAssistantOrder(preview);
        }
    }
}
</script>

<template>
    <div class="absolute inset-0 bg-background">
        <MenuStudio context="tpv" class="h-full" :user-name="userName" :admin="isAdmin" @reply="onReply">
            <template #order="{ order }">
                <AssistantOrderCard :order="order" />
            </template>
        </MenuStudio>
    </div>
</template>
