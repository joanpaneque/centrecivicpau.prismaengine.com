<script setup lang="ts">
import { computed } from 'vue';
import MenuStudio from '@/components/assistant/MenuStudio.vue';
import { operator, state } from '@/pos/store';

const userName = computed(() => operator()?.name ?? state.me?.name ?? null);
</script>

<template>
    <div class="absolute inset-0 bg-background">
        <MenuStudio context="tpv" class="h-full" :user-name="userName" />
    </div>
</template>
