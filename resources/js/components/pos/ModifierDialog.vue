<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import AllergenIcons from '@/components/pos/AllergenIcons.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { productModifierGroups } from '@/pos/catalog';
import { formatMoney } from '@/pos/money';
import type { DraftLine } from '@/pos/orders';
import type { LineModifier, Product } from '@/pos/types';

const props = defineProps<{ product: Product | null; line?: DraftLine | null; defaultCourse?: number | null }>();
const emit = defineEmits<{ close: []; save: [data: { modifiers: LineModifier[]; note: string; course: number | null; quantity: number }]; remove: [] }>();

const open = ref(true);
const groups = computed(() => (props.product ? productModifierGroups(props.product) : []));
const selected = reactive<Record<number, number[]>>({});
const form = reactive({
    note: props.line?.note ?? '',
    course: props.line?.course ?? props.defaultCourse ?? null,
    quantity: props.line?.quantity ?? 1,
});

for (const group of groups.value) {
    selected[group.id] = (props.line?.modifiers ?? []).filter((m) => m.id && group.modifiers.some((gm) => gm.id === m.id)).map((m) => m.id as number);
}

const missing = computed(() => groups.value.filter((g) => g.required && !(selected[g.id]?.length ?? 0)));

const unit = computed(() => {
    const base = props.line?.unitPrice ?? props.product?.price ?? 0;
    const extras = groups.value.flatMap((g) => g.modifiers.filter((m) => selected[g.id]?.includes(m.id))).reduce((sum, m) => sum + m.priceDelta, 0);

    return base + extras;
});

function toggle(groupId: number, modifierId: number, multiple: boolean): void {
    const current = selected[groupId] ?? [];

    if (multiple) {
        selected[groupId] = current.includes(modifierId) ? current.filter((id) => id !== modifierId) : [...current, modifierId];
    } else {
        selected[groupId] = current.includes(modifierId) ? [] : [modifierId];
    }
}

function save(): void {
    const modifiers: LineModifier[] = groups.value.flatMap((g) =>
        g.modifiers.filter((m) => selected[g.id]?.includes(m.id)).map((m) => ({ id: m.id, name: m.name, priceDelta: m.priceDelta })),
    );
    emit('save', { modifiers, note: form.note.trim(), course: form.course, quantity: Math.max(1, form.quantity) });
    close();
}

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="max-h-[92svh] max-w-xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ product ? tr(product.name) : line ? tr(line.name) : '' }}</DialogTitle>
                <DialogDescription class="flex items-center gap-2">
                    {{ formatMoney(unit) }}
                    <AllergenIcons v-if="product" :allergens="product.allergens" />
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-5">
                <div v-for="group in groups" :key="group.id">
                    <p class="mb-2 text-sm font-semibold">
                        {{ tr(group.name) }}
                        <span class="font-normal text-slate-500">· {{ group.multiple ? t('order.chooseMany') : t('order.chooseOne') }}</span>
                        <span v-if="group.required" class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-800">{{ t('order.required') }}</span>
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="modifier in group.modifiers"
                            :key="modifier.id"
                            type="button"
                            class="min-h-11 rounded-xl border-2 px-3 text-sm font-medium"
                            :class="selected[group.id]?.includes(modifier.id) ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200 bg-white'"
                            @click="toggle(group.id, modifier.id, group.multiple)"
                        >
                            {{ tr(modifier.name) }}
                            <span v-if="modifier.priceDelta" class="opacity-75">{{ modifier.priceDelta > 0 ? '+' : '' }}{{ formatMoney(modifier.priceDelta) }}</span>
                        </button>
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-semibold">{{ t('order.course') }}</p>
                    <div class="flex gap-2">
                        <button
                            v-for="course in [null, 1, 2, 3]"
                            :key="String(course)"
                            type="button"
                            class="h-11 flex-1 rounded-xl border-2 text-sm font-semibold"
                            :class="form.course === course ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200'"
                            @click="form.course = course"
                        >
                            {{ course ? t(`order.courses.${course}`) : t('order.noCourse') }}
                        </button>
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-semibold">{{ t('order.note') }}</p>
                    <textarea v-model="form.note" rows="2" :placeholder="t('order.notePlaceholder')" class="w-full rounded-xl border border-slate-200 p-3 text-base outline-none focus:border-[#00056a]" />
                </div>

                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold">{{ t('order.quantity') }}</p>
                    <div class="flex items-center gap-3">
                        <button type="button" class="flex size-11 items-center justify-center rounded-xl bg-slate-100" @click="form.quantity = Math.max(1, form.quantity - 1)"><Minus class="size-5" /></button>
                        <span class="w-8 text-center text-xl font-bold">{{ form.quantity }}</span>
                        <button type="button" class="flex size-11 items-center justify-center rounded-xl bg-slate-100" @click="form.quantity++"><Plus class="size-5" /></button>
                    </div>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button v-if="line" variant="outline" class="text-red-700" @click="emit('remove'); close()">{{ t('common.delete') }}</Button>
                <Button class="h-12 flex-1 bg-[#00056a] text-base" :disabled="missing.length > 0" @click="save">
                    {{ line ? t('common.save') : t('order.addToOrder') }} · {{ formatMoney(unit * form.quantity) }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
