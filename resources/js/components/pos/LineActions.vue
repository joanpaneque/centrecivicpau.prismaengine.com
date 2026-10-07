<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tl, tr } from '@/i18n';
import { formatMoney, parseMoney } from '@/pos/money';
import { voidOrderLine } from '@/pos/orders';
import { enqueue } from '@/pos/sync';
import type { Order, OrderLine } from '@/pos/types';

const props = defineProps<{ order: Order; line: OrderLine | null }>();
const emit = defineEmits<{ close: [] }>();

const open = ref(true);
const mode = ref<'menu' | 'void' | 'discount'>(props.line ? 'menu' : 'discount');
const available = computed(() => (props.line ? props.line.quantity - props.line.paidQuantity : 0));

const form = reactive({
    quantity: available.value,
    reason: '',
    type: 'percent' as 'percent' | 'amount',
    value: '' as string,
});

const quickReasons = computed(() => tl(mode.value === 'void' ? 'order.voidReasons' : 'order.discountReasons'));

function close(): void {
    open.value = false;
    emit('close');
}

async function confirmVoid(): Promise<void> {
    if (!props.line || !form.reason.trim()) {
        return;
    }

    await voidOrderLine(props.order, props.line, form.quantity, form.reason.trim());
    close();
}

async function confirmDiscount(remove = false): Promise<void> {
    const value = remove ? 0 : form.type === 'percent' ? Number(form.value.replace(',', '.')) : parseMoney(form.value);

    if (!remove && (!value || !form.reason.trim())) {
        return;
    }

    if (props.line) {
        await enqueue('line.discount', { lineUuid: props.line.uuid, type: remove ? null : form.type, value, reason: form.reason.trim() || null });
    } else {
        await enqueue('order.discount', { orderUuid: props.order.uuid, percent: remove ? 0 : value, reason: form.reason.trim() || null });
    }

    close();
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>{{ line ? `${line.quantity} × ${tr(line.name)}` : t('order.discountOrder') }}</DialogTitle>
                <DialogDescription v-if="line">{{ formatMoney(line.unitPrice) }}</DialogDescription>
                <DialogDescription v-else class="sr-only">{{ t('order.discount') }}</DialogDescription>
            </DialogHeader>

            <div v-if="mode === 'menu'" class="grid gap-2">
                <Button variant="outline" class="h-14 justify-start text-base text-red-700" :disabled="available <= 0" @click="mode = 'void'">{{ t('order.voidLine') }}</Button>
                <Button variant="outline" class="h-14 justify-start text-base" @click="mode = 'discount'">{{ t('order.discountLine') }}</Button>
                <Button v-if="line?.discountType" variant="outline" class="h-14 justify-start text-base" @click="confirmDiscount(true)">{{ t('order.removeDiscount') }}</Button>
            </div>

            <div v-else-if="mode === 'void'" class="grid gap-4">
                <div v-if="available > 1" class="flex items-center justify-between">
                    <span class="text-sm font-semibold">{{ t('order.voidQuantity') }}</span>
                    <div class="flex items-center gap-3">
                        <button type="button" class="flex size-11 items-center justify-center rounded-xl bg-slate-100" @click="form.quantity = Math.max(1, form.quantity - 1)"><Minus class="size-5" /></button>
                        <span class="w-8 text-center text-xl font-bold">{{ form.quantity }}</span>
                        <button type="button" class="flex size-11 items-center justify-center rounded-xl bg-slate-100" @click="form.quantity = Math.min(available, form.quantity + 1)"><Plus class="size-5" /></button>
                    </div>
                </div>
            </div>

            <div v-else class="grid gap-4">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="h-11 rounded-xl border-2 font-semibold" :class="form.type === 'percent' ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200'" @click="form.type = 'percent'">%</button>
                    <button v-if="line" type="button" class="h-11 rounded-xl border-2 font-semibold" :class="form.type === 'amount' ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200'" @click="form.type = 'amount'">€</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-for="v in form.type === 'percent' ? ['5', '10', '15', '20', '50', '100'] : ['0,50', '1', '2', '5']" :key="v" type="button" class="h-10 rounded-lg bg-slate-100 px-3 font-semibold" @click="form.value = v">
                        {{ v }}{{ form.type === 'percent' ? '%' : ' €' }}
                    </button>
                </div>
                <Input v-model="form.value" inputmode="decimal" class="h-12 text-lg" :placeholder="form.type === 'percent' ? t('order.percent') : t('order.amount')" />
            </div>

            <div v-if="mode !== 'menu'" class="grid gap-2">
                <p class="text-sm font-semibold">{{ t('common.reason') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="r in quickReasons" :key="r" type="button" class="rounded-full bg-slate-100 px-3 py-1.5 text-sm" @click="form.reason = r">{{ r }}</button>
                </div>
                <Input v-model="form.reason" class="h-11" :placeholder="t('common.reason')" />
            </div>

            <DialogFooter v-if="mode !== 'menu'">
                <Button v-if="mode === 'void'" variant="destructive" class="h-12 w-full" :disabled="!form.reason.trim()" @click="confirmVoid">{{ t('order.void') }} {{ form.quantity }}</Button>
                <Button v-else class="h-12 w-full bg-[#00056a]" :disabled="!form.reason.trim() || !form.value" @click="confirmDiscount()">{{ t('common.apply') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
