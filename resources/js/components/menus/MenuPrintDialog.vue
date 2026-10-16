<script setup lang="ts">
import { Printer } from '@lucide/vue';
import { ref } from 'vue';
import MenuPrintPanel from '@/components/menus/MenuPrintPanel.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t } from '@/i18n';

/** The print panel as a dialog (back office). */
defineProps<{ menuId: number; menuName: string; template?: string | null }>();
const emit = defineEmits<{ close: []; template: [template: string] }>();

const open = ref(true);

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="max-h-[96svh] max-w-4xl overflow-y-auto p-4 sm:max-w-4xl sm:p-6">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Printer class="size-5" /> {{ t('menuPrint.title') }}</DialogTitle>
                <DialogDescription>{{ menuName }}</DialogDescription>
            </DialogHeader>
            <MenuPrintPanel :menu-id="menuId" :menu-name="menuName" :template="template" @template="(value) => emit('template', value)" />
        </DialogContent>
    </Dialog>
</template>
