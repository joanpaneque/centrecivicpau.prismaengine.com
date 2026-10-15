<script setup lang="ts">
import { computed, ref } from 'vue';
import MoneyKeypad from '@/components/pos/MoneyKeypad.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tr } from '@/i18n';
import { formatMoney, parseMoney } from '@/pos/money';
import type { LineEdit } from '@/pos/orders';
import { state } from '@/pos/store';
import type { T9n } from '@/pos/types';

const props = defineProps<{
    name: T9n;
    unitPrice: number;
    unpriced: boolean;
    productId: number | null;
    title?: string;
    description?: string;
    confirmLabel?: string;
}>();
const emit = defineEmits<{ save: [edit: LineEdit]; close: [] }>();

const open = ref(true);
const original = tr(props.name);
const nameInput = ref(original);
// Starts empty so typing replaces the price; left empty, the current price is kept.
const priceRaw = ref('');
const product = computed(() => (props.productId ? (state.products[props.productId] ?? null) : null));
// A product without a catalog price keeps the price "for always" by default.
const saveToCatalog = ref(!!product.value && product.value.price === null);
const cents = computed(() => (priceRaw.value.trim() !== '' ? parseMoney(priceRaw.value) : props.unitPrice));
const canSave = computed(() => (priceRaw.value.trim() !== '' || !props.unpriced) && nameInput.value.trim() !== '');

function save(): void {
    if (!canSave.value) {
        return;
    }

    const typed = nameInput.value.trim();
    emit('save', { unitPrice: cents.value, name: typed !== original ? { ca: typed, es: typed } : null, saveToCatalog: !!product.value && saveToCatalog.value });
    close();
}

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="max-h-[94svh] max-w-md overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ title ?? t('order.editLine') }}</DialogTitle>
                <DialogDescription>{{ description ?? original }}</DialogDescription>
            </DialogHeader>
            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('common.name') }}</label>
                    <Input v-model="nameInput" class="h-12 text-lg" autocomplete="off" />
                </div>
                <div>
                    <p class="mb-1 flex justify-between text-sm font-medium">
                        {{ t('common.price') }}
                        <span class="font-normal text-slate-500">{{ unpriced ? t('order.unpriced') : t('order.currentPrice', { price: formatMoney(unitPrice) }) }}</span>
                    </p>
                    <MoneyKeypad v-model="priceRaw" embedded :min-cents="0" />
                </div>
                <label v-if="product" class="flex items-start gap-3 rounded-xl bg-slate-50 p-3">
                    <Checkbox v-model="saveToCatalog" class="mt-0.5 size-5" />
                    <span>
                        <span class="block text-sm font-semibold">{{ t('order.saveToCatalog') }}</span>
                        <span class="block text-xs text-slate-500">{{ saveToCatalog ? t('order.saveToCatalogOn') : t('order.saveToCatalogOff') }}</span>
                    </span>
                </label>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" class="h-12" @click="close">{{ t('common.cancel') }}</Button>
                <Button class="h-12 flex-1 bg-[#00056a] text-base" :disabled="!canSave" @click="save">
                    {{ confirmLabel ?? t('common.save') }}<template v-if="canSave"> · {{ formatMoney(cents) }}</template>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
