<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Banknote, CreditCard } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useI18n } from '@/i18n';
import { money } from '@/lib/format';
import { payments as paymentsRoute } from '@/routes/admin/tickets';

type Method = 'cash' | 'card';

const props = defineProps<{ ticket: { id: number; fullNumber: string; payments: { id: number; method: Method; amount: number }[] } | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const open = computed({ get: () => props.ticket !== null, set: (v) => !v && emit('close') });
const methods = ref<Record<number, Method>>({});
const saving = ref(false);

watch(
    () => props.ticket,
    (ticket) => (methods.value = Object.fromEntries((ticket?.payments ?? []).map((p) => [p.id, p.method]))),
    { immediate: true },
);

const changed = computed(() => props.ticket?.payments.some((p) => methods.value[p.id] !== p.method) ?? false);

function save(): void {
    if (!props.ticket) {
        return;
    }

    saving.value = true;
    router.patch(
        paymentsRoute.url(props.ticket.id),
        { payments: props.ticket.payments.map((p) => ({ id: p.id, method: methods.value[p.id] })) },
        { preserveScroll: true, onSuccess: () => emit('close'), onFinish: () => (saving.value = false) },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="ticket" class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('admin.tickets.paymentsTitle', { number: ticket.fullNumber }) }}</DialogTitle>
                <DialogDescription>{{ t('admin.tickets.paymentsHelp') }}</DialogDescription>
            </DialogHeader>
            <div class="space-y-3">
                <div v-for="payment in ticket.payments" :key="payment.id" class="flex items-center gap-3">
                    <span class="w-24 font-semibold tabular-nums">{{ money(payment.amount) }}</span>
                    <div class="grid flex-1 grid-cols-2 gap-2">
                        <button
                            v-for="m in ['cash', 'card'] as Method[]"
                            :key="m"
                            type="button"
                            class="flex h-11 items-center justify-center gap-2 rounded-lg border-2 text-sm font-medium"
                            :class="methods[payment.id] === m ? 'border-[#00056a] bg-[#00056a]/5 text-[#00056a] dark:border-blue-400 dark:text-blue-300' : 'border-transparent bg-muted/50'"
                            @click="methods[payment.id] = m"
                        >
                            <component :is="m === 'cash' ? Banknote : CreditCard" class="size-4" /> {{ t(`cashier.${m}`) }}
                        </button>
                    </div>
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="emit('close')">{{ t('common.cancel') }}</Button>
                <Button :disabled="!changed || saving" @click="save">{{ t('common.save') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
