<script setup lang="ts">
import { Check, ChevronLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { menusToday } from '@/pos/catalog';
import { formatMoney } from '@/pos/money';
import { draftFromMenu } from '@/pos/orders';
import type { DraftLine } from '@/pos/orders';
import { state } from '@/pos/store';
import type { SetMenu, SetMenuItem } from '@/pos/types';

const emit = defineEmits<{ close: []; add: [line: DraftLine] }>();

const open = ref(true);
const menu = ref<SetMenu | null>(menusToday.value.length === 1 ? menusToday.value[0] : null);
const step = ref(0);
const picks = ref<Record<number, number[]>>({});

const sections = computed(() => menu.value?.sections.filter((s) => s.items.length > 0) ?? []);
const section = computed(() => sections.value[step.value] ?? null);
const isLast = computed(() => step.value >= sections.value.length - 1);

const total = computed(() => {
    if (!menu.value) {
        return 0;
    }

    let sum = menu.value.price;

    for (const s of sections.value) {
        for (const itemId of picks.value[s.id] ?? []) {
            sum += s.items.find((i) => i.id === itemId)?.supplement ?? 0;
        }
    }

    return sum;
});

function choose(item: SetMenuItem): void {
    const s = section.value;

    if (!s) {
        return;
    }

    const current = picks.value[s.id] ?? [];

    if (s.choices <= 1) {
        picks.value[s.id] = [item.id];
        setTimeout(next, 150);

        return;
    }

    if (current.includes(item.id)) {
        picks.value[s.id] = current.filter((id) => id !== item.id);
    } else if (current.length < s.choices) {
        picks.value[s.id] = [...current, item.id];
    }
}

function soldOut(item: SetMenuItem): boolean {
    return !!item.productId && !!state.products[item.productId]?.soldOut;
}

function next(): void {
    if (isLast.value) {
        finish();
    } else {
        step.value++;
    }
}

function finish(): void {
    if (!menu.value) {
        return;
    }

    const choices = sections.value.flatMap((s) =>
        (picks.value[s.id] ?? []).map((itemId) => {
            const item = s.items.find((i) => i.id === itemId)!;

            return { name: item.name, productId: item.productId, destinationId: item.destinationId, supplement: item.supplement, course: s.course };
        }),
    );

    emit('add', draftFromMenu(menu.value, choices));
    close();
}

function close(): void {
    open.value = false;
    emit('close');
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="max-h-[92svh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <button v-if="menu && (step > 0 || menusToday.length > 1)" type="button" class="rounded p-1 hover:bg-slate-100" @click="step > 0 ? step-- : (menu = null)">
                        <ChevronLeft class="size-5" />
                    </button>
                    {{ menu ? tr(menu.name) : t('setMenu.choose') }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="menu">{{ formatMoney(total) }}<template v-if="menu.includes"> · {{ t('setMenu.includes') }}: {{ tr(menu.includes) }}</template></template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="!menu" class="grid gap-3 sm:grid-cols-2">
                <p v-if="!menusToday.length" class="text-sm text-slate-500">{{ t('setMenu.noneToday') }}</p>
                <button
                    v-for="m in menusToday"
                    :key="m.id"
                    type="button"
                    class="rounded-xl border-2 border-slate-200 p-4 text-left hover:border-[#00056a]"
                    @click="menu = m; step = 0; picks = {}"
                >
                    <span class="block text-lg font-semibold">{{ tr(m.name) }}</span>
                    <span class="block text-[#00056a]">{{ formatMoney(m.price) }}</span>
                    <span v-if="m.includes" class="block text-sm text-slate-500">{{ tr(m.includes) }}</span>
                </button>
            </div>

            <template v-else-if="section">
                <div class="flex gap-1">
                    <span v-for="(s, index) in sections" :key="s.id" class="h-1.5 flex-1 rounded-full" :class="index <= step ? 'bg-[#00056a]' : 'bg-slate-200'" />
                </div>
                <p class="text-lg font-semibold">
                    {{ tr(section.name) }}
                    <span class="text-sm font-normal text-slate-500">· {{ section.choices > 1 ? t('setMenu.pick', { count: section.choices }) : t('setMenu.pickOne') }}</span>
                </p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="item in section.items"
                        :key="item.id"
                        type="button"
                        :disabled="soldOut(item)"
                        class="flex min-h-16 items-center justify-between gap-2 rounded-xl border-2 p-3 text-left disabled:opacity-40"
                        :class="picks[section.id]?.includes(item.id) ? 'border-[#00056a] bg-[#00056a]/5' : 'border-slate-200'"
                        @click="choose(item)"
                    >
                        <span>
                            <span class="block font-medium">{{ tr(item.name) }}</span>
                            <span v-if="item.supplement" class="text-sm text-amber-700">+{{ formatMoney(item.supplement) }} {{ t('setMenu.supplement').toLowerCase() }}</span>
                            <span v-if="soldOut(item)" class="text-sm text-red-600">{{ t('order.soldOut') }}</span>
                        </span>
                        <Check v-if="picks[section.id]?.includes(item.id)" class="size-5 text-[#00056a]" />
                    </button>
                </div>
            </template>

            <DialogFooter v-if="menu">
                <Button class="h-12 bg-[#00056a]" @click="isLast ? finish() : next()">
                    {{ isLast ? t('setMenu.finish') : t('setMenu.next') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
