<script setup lang="ts">
import { ArrowLeft, Check, Minus, Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { productColor, productModifierGroups, readableText } from '@/pos/catalog';
import { formatMoney } from '@/pos/money';
import type { LineModifier, Product } from '@/pos/types';

/**
 * Step-by-step options for a product (coffee: milk → sugar…): one screen per option group
 * with big squares, then an optional note. A single-choice group moves on by itself, and
 * "Add now" is always there so a plain coffee is still two taps.
 */
const props = defineProps<{ product: Product; course: number | null }>();
const emit = defineEmits<{ add: [data: { modifiers: LineModifier[]; note: string; course: number | null; quantity: number }]; close: [] }>();

const open = ref(true);
const groups = computed(() => productModifierGroups(props.product));
const step = ref(0);
const selected = reactive<Record<number, number[]>>({});
const note = ref('');
const quantity = ref(1);

const isFinal = computed(() => step.value >= groups.value.length);
const group = computed(() => (isFinal.value ? null : groups.value[step.value]));
const missing = computed(() => groups.value.filter((g) => g.required && !(selected[g.id]?.length ?? 0)));
const chosen = computed(() => groups.value.flatMap((g) => g.modifiers.filter((m) => selected[g.id]?.includes(m.id))));
const unit = computed(() => (props.product.price ?? 0) + chosen.value.reduce((sum, m) => sum + m.priceDelta, 0));
const color = computed(() => productColor(props.product) ?? '#00056a');

function toggle(modifierId: number): void {
    const g = group.value;

    if (!g) {
        return;
    }

    const current = selected[g.id] ?? [];

    if (g.multiple) {
        selected[g.id] = current.includes(modifierId) ? current.filter((id) => id !== modifierId) : [...current, modifierId];

        return;
    }

    selected[g.id] = current.includes(modifierId) ? [] : [modifierId];

    if (selected[g.id].length) {
        next();
    }
}

function next(): void {
    step.value = Math.min(groups.value.length, step.value + 1);
}

function back(): void {
    step.value = Math.max(0, step.value - 1);
}

function skip(): void {
    if (group.value) {
        selected[group.value.id] = [];
    }

    next();
}

function add(): void {
    if (missing.value.length) {
        step.value = groups.value.indexOf(missing.value[0]);

        return;
    }

    const modifiers: LineModifier[] = chosen.value.map((m) => ({ id: m.id, name: m.name, priceDelta: m.priceDelta }));
    emit('add', { modifiers, note: note.value.trim(), course: props.course, quantity: quantity.value });
    close();
}

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="flex max-h-[94svh] max-w-2xl flex-col overflow-hidden p-0">
            <DialogHeader class="shrink-0 px-5 pt-5" :style="{ background: color, color: readableText(color) }">
                <DialogTitle class="pb-1 text-2xl">{{ tr(product.name) }}</DialogTitle>
                <DialogDescription class="flex items-center gap-2 pb-3" :style="{ color: readableText(color) }">
                    <span v-if="product.price !== null" class="font-semibold">{{ formatMoney(unit) }}</span>
                    <span v-else class="font-semibold">{{ t('order.unpriced') }}</span>
                    <span v-for="m in chosen" :key="m.id" class="rounded-full bg-white/25 px-2 py-0.5 text-xs font-semibold">{{ tr(m.name) }}</span>
                </DialogDescription>
            </DialogHeader>

            <div class="flex shrink-0 gap-1 px-5 pt-3">
                <span
                    v-for="(g, index) in [...groups, null]"
                    :key="g ? g.id : 'final'"
                    class="h-1.5 flex-1 rounded-full"
                    :class="index < step ? 'bg-[#00056a]' : index === step ? 'bg-[#00056a]/60' : 'bg-slate-200'"
                />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <template v-if="group">
                    <p class="mb-3 text-lg font-bold">
                        {{ tr(group.name) }}
                        <span class="text-sm font-normal text-slate-500">· {{ group.multiple ? t('order.chooseMany') : t('order.chooseOne') }}</span>
                        <span v-if="group.required" class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">{{ t('order.required') }}</span>
                    </p>
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-3">
                        <button
                            v-for="modifier in group.modifiers"
                            :key="modifier.id"
                            type="button"
                            class="relative flex aspect-[4/3] flex-col items-center justify-center rounded-2xl border-4 p-2 text-center text-base font-bold shadow-sm transition active:scale-95"
                            :class="selected[group.id]?.includes(modifier.id) ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200 bg-white text-slate-800'"
                            @click="toggle(modifier.id)"
                        >
                            <Check v-if="selected[group.id]?.includes(modifier.id)" class="absolute top-2 right-2 size-5" />
                            {{ tr(modifier.name) }}
                            <span v-if="modifier.priceDelta" class="mt-1 text-sm font-semibold opacity-80">{{ modifier.priceDelta > 0 ? '+' : '' }}{{ formatMoney(modifier.priceDelta) }}</span>
                        </button>
                    </div>
                </template>

                <template v-else>
                    <p class="mb-2 text-lg font-bold">{{ t('order.wizardSummary') }}</p>
                    <p class="mb-2 text-sm font-semibold">{{ t('order.note') }}</p>
                    <textarea v-model="note" rows="2" :placeholder="t('order.notePlaceholder')" class="w-full rounded-xl border border-slate-200 p-3 text-base outline-none focus:border-[#00056a]" />
                    <div class="mt-4 flex items-center justify-between">
                        <p class="text-sm font-semibold">{{ t('order.quantity') }}</p>
                        <div class="flex items-center gap-3">
                            <button type="button" class="flex size-12 items-center justify-center rounded-xl bg-slate-100" @click="quantity = Math.max(1, quantity - 1)"><Minus class="size-5" /></button>
                            <span class="w-8 text-center text-2xl font-bold">{{ quantity }}</span>
                            <button type="button" class="flex size-12 items-center justify-center rounded-xl bg-slate-100" @click="quantity++"><Plus class="size-5" /></button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="flex shrink-0 gap-2 border-t bg-slate-50 p-3">
                <Button v-if="step > 0" variant="outline" class="h-14 px-4" @click="back"><ArrowLeft class="size-5" /></Button>
                <Button v-if="group && !group.required" variant="outline" class="h-14 px-5 text-base" @click="skip">{{ t('order.wizardSkip') }}</Button>
                <Button v-if="group && group.multiple" variant="outline" class="h-14 px-5 text-base" :disabled="group.required && !(selected[group.id]?.length ?? 0)" @click="next">
                    {{ t('order.wizardNext') }}
                </Button>
                <Button class="h-14 flex-1 bg-emerald-600 text-lg hover:bg-emerald-700" @click="add">
                    {{ isFinal ? t('order.addToOrder') : t('order.addNow') }}<template v-if="product.price !== null"> · {{ formatMoney(unit * quantity) }}</template>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
