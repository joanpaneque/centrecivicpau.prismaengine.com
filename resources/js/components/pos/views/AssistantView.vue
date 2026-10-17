<script setup lang="ts">
import type { ChatMessage } from '@/assistant/api';
import MenuStudio from '@/components/assistant/MenuStudio.vue';
import AssistantOrderCard from '@/components/pos/AssistantOrderCard.vue';
import { applyAssistantOrder } from '@/pos/assistantOrders';

/**
 * The assistant on the POS, for everyone: it answers questions and takes orders by voice;
 * administrators also manage the carta, menus and printable cartas and see reports. Orders
 * dictated here are added to the table on this tablet as soon as the reply arrives.
 */
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
        <MenuStudio context="tpv" class="h-full" @reply="onReply">
            <template #order="{ order }">
                <AssistantOrderCard :order="order" />
            </template>
        </MenuStudio>
    </div>
</template>
