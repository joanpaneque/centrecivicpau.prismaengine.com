<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useI18n } from '@/i18n';

const open = defineModel<boolean>('open', { default: false });

defineProps<{
    title?: string;
    description?: string;
    confirmLabel?: string;
    destructive?: boolean;
    processing?: boolean;
}>();

const emit = defineEmits<{ confirm: [] }>();
const { t } = useI18n();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title ?? t('common.areYouSure') }}</DialogTitle>
                <DialogDescription v-if="description">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="open = false">
                    {{ t('common.cancel') }}
                </Button>
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel ?? t('common.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
