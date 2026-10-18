<script setup lang="ts">
import { ArrowRightLeft, Minus, Plus, Settings } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tl, tr } from '@/i18n';
import { formatMoney, parseMoney } from '@/pos/money';
import { movableUnits, voidLines } from '@/pos/orders';
import type { LineGroup } from '@/pos/orders';
import { enqueue } from '@/pos/sync';
import type { Order } from '@/pos/types';

/** Actions on a (grouped) sent line, or on the whole order when `group` is null (discount). */
const props = defineProps<{ order: Order; group: LineGroup | null }>();
const emit = defineEmits<{ close: []; edit: [group: LineGroup]; move: [group: LineGroup, quantity: number] }>();

const open = ref(true);
const mode = ref<'menu' | 'void' | 'discount' | 'move'>(props.group ? 'menu' : 'discount');
const line = computed(() => props.group?.first ?? null);
/** Units that can go to another table (not paid). */
const movable = computed(() => (props.group ? movableUnits(props.order, props.group.lines) : 0));
const moveQuantity = ref(movable.value);

const available = computed(() => (props.group ? props.group.lines.reduce((sum, l) => sum + (l.voided ? 0 : l.quantity - l.paidQuantity), 0) : 0));

const form = reactive({
    quantity: available.value,
    reason: '',
    type: 'percent' as 'percent' | 'amount',
    value: '' as string,
});

const discountReasons = computed(() => tl('order.discountReasons'));

function close(): void {
    open.value = false;
    emit('close');
}

async function confirmVoid(): Promise<void> {
    if (!props.group || form.quantity <= 0) {
        return;
    }

    await voidLines(props.order, props.group.lines, form.quantity);
    close();
}

function startMove(): void {
    if (movable.value === 1) {
        confirmMove();
    } else {
        moveQuantity.value = movable.value;
        mode.value = 'move';
    }
}

function confirmMove(): void {
    if (props.group && movable.value > 0) {
        emit('move', props.group, movable.value === 1 ? 1 : moveQuantity.value);
    }

    close();
}

function edit(): void {
    if (props.group) {
        emit('edit', props.group);
    }

    close();
}

async function confirmDiscount(remove = false): Promise<void> {
    const value = remove ? 0 : form.type === 'percent' ? Number(form.value.replace(',', '.')) : parseMoney(form.value);

    if (!remove && (!value || !form.reason.trim())) {
        return;
    }

    if (props.group) {
        const targets = props.group.lines.filter((l) => !l.voided);
        const units = targets.reduce((sum, l) => sum + l.quantity, 0) || 1;
        let left = value;

        for (const [index, target] of targets.entries()) {
            // A euro discount is spread over the grouped lines; a percentage applies to each.
            const share = form.type === 'amount' && !remove ? (index === targets.length - 1 ? left : Math.round((value * target.quantity) / units)) : value;
            left -= share;
            await enqueue('line.discount', { lineUuid: target.uuid, type: remove ? null : form.type, value: share, reason: form.reason.trim() || null });
        }
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
                <DialogTitle>{{ group && line ? `${group.quantity} × ${tr(line.name)}` : t('order.discountOrder') }}</DialogTitle>
                <DialogDescription v-if="line">{{ line.unpriced ? t('order.unpriced') : formatMoney(line.unitPrice) }}</DialogDescription>
                <DialogDescription v-else class="sr-only">{{ t('order.discount') }}</DialogDescription>
            </DialogHeader>

            <div v-if="mode === 'menu'" class="grid gap-2">
                <Button variant="outline" class="h-14 justify-start text-base text-red-700" :disabled="available <= 0" @click="available === 1 ? confirmVoid() : (mode = 'void')">
                    {{ available === 1 ? t('order.voidOne') : t('order.voidLine') }}
                </Button>
                <Button variant="outline" class="h-14 justify-start gap-2 text-base" :disabled="movable <= 0" @click="startMove">
                    <ArrowRightLeft class="size-5" /> {{ t('order.moveLine') }}
                </Button>
                <Button variant="outline" class="h-14 justify-start gap-2 text-base" @click="edit"><Settings class="size-5" /> {{ t('order.editLine') }}</Button>
                <Button variant="outline" class="h-14 justify-start text-base" @click="mode = 'discount'">{{ t('order.discountLine') }}</Button>
                <Button v-if="line?.discountType" variant="outline" class="h-14 justify-start text-base" @click="confirmDiscount(true)">{{ t('order.removeDiscount') }}</Button>
            </div>

            <div v-else-if="mode === 'move'" class="grid gap-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold">{{ t('order.moveQuantity') }}</span>
                    <div class="flex items-center gap-3">
                        <button type="button" class="flex size-12 items-center justify-center rounded-xl bg-slate-100" @click="moveQuantity = Math.max(1, moveQuantity - 1)"><Minus class="size-5" /></button>
                        <span class="w-8 text-center text-2xl font-bold">{{ moveQuantity }}</span>
                        <button type="button" class="flex size-12 items-center justify-center rounded-xl bg-slate-100" @click="moveQuantity = Math.min(movable, moveQuantity + 1)"><Plus class="size-5" /></button>
                    </div>
                </div>
            </div>

            <div v-else-if="mode === 'void'" class="grid gap-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold">{{ t('order.voidQuantity') }}</span>
                    <div class="flex items-center gap-3">
                        <button type="button" class="flex size-12 items-center justify-center rounded-xl bg-slate-100" @click="form.quantity = Math.max(1, form.quantity - 1)"><Minus class="size-5" /></button>
                        <span class="w-8 text-center text-2xl font-bold">{{ form.quantity }}</span>
                        <button type="button" class="flex size-12 items-center justify-center rounded-xl bg-slate-100" @click="form.quantity = Math.min(available, form.quantity + 1)"><Plus class="size-5" /></button>
                    </div>
                </div>
            </div>

            <div v-else class="grid gap-4">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="h-11 rounded-xl border-2 font-semibold" :class="form.type === 'percent' ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200'" @click="form.type = 'percent'">%</button>
                    <button v-if="group" type="button" class="h-11 rounded-xl border-2 font-semibold" :class="form.type === 'amount' ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200'" @click="form.type = 'amount'">€</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-for="v in form.type === 'percent' ? ['5', '10', '15', '20', '50', '100'] : ['0,50', '1', '2', '5']" :key="v" type="button" class="h-10 rounded-lg bg-slate-100 px-3 font-semibold" @click="form.value = v">
                        {{ v }}{{ form.type === 'percent' ? '%' : ' €' }}
                    </button>
                </div>
                <Input v-model="form.value" inputmode="decimal" class="h-12 text-lg" :placeholder="form.type === 'percent' ? t('order.percent') : t('order.amount')" />
                <div class="grid gap-2">
                    <p class="text-sm font-semibold">{{ t('common.reason') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="r in discountReasons" :key="r" type="button" class="rounded-full bg-slate-100 px-3 py-1.5 text-sm" @click="form.reason = r">{{ r }}</button>
                    </div>
                    <Input v-model="form.reason" class="h-11" :placeholder="t('common.reason')" />
                </div>
            </div>

            <DialogFooter v-if="mode !== 'menu'">
                <Button v-if="mode === 'void'" variant="destructive" class="h-12 w-full" @click="confirmVoid">{{ t('order.void') }} {{ form.quantity }}</Button>
                <Button v-else-if="mode === 'move'" class="h-12 w-full bg-[#00056a]" @click="confirmMove">
                    <ArrowRightLeft class="size-5" /> {{ t('order.moveLineChoose', { count: moveQuantity }) }}
                </Button>
                <Button v-else class="h-12 w-full bg-[#00056a]" :disabled="!form.reason.trim() || !form.value" @click="confirmDiscount()">{{ t('common.apply') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
