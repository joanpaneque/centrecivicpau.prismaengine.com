<script setup lang="ts">
import { Ban, Search, Star, UtensilsCrossed, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AllergenIcons from '@/components/pos/AllergenIcons.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { categoryProducts, frequentProducts, menusToday, productColor, searchProducts } from '@/pos/catalog';
import { formatMoney } from '@/pos/money';
import { sorted } from '@/pos/store';
import { enqueue } from '@/pos/sync';
import type { Product } from '@/pos/types';

const emit = defineEmits<{ pick: [product: Product]; menu: [] }>();

const query = ref('');
const tab = ref<string>(frequentProducts.value.length ? 'frequent' : '');
const subCategory = ref<number | null>(null);
const info = ref<Product | null>(null);

const roots = computed(() => sorted.categories.value.filter((c) => !c.parentId));
const currentRoot = computed(() => (tab.value.startsWith('c') ? Number(tab.value.slice(1)) : null));
const children = computed(() => sorted.categories.value.filter((c) => currentRoot.value !== null && c.parentId === currentRoot.value));

if (!tab.value && roots.value[0]) {
    tab.value = `c${roots.value[0].id}`;
}

const products = computed(() => {
    if (query.value.trim()) {
        return searchProducts(query.value);
    }

    if (tab.value === 'frequent') {
        return frequentProducts.value;
    }

    if (currentRoot.value !== null) {
        return categoryProducts(subCategory.value ?? currentRoot.value);
    }

    return [];
});

function selectTab(value: string): void {
    tab.value = value;
    subCategory.value = null;
    query.value = '';
}

function pick(product: Product): void {
    if (product.soldOut) {
        info.value = product;

        return;
    }

    emit('pick', product);
}

let pressTimer: ReturnType<typeof setTimeout> | null = null;
let longPressed = false;

function pressStart(product: Product): void {
    longPressed = false;
    pressTimer = setTimeout(() => {
        longPressed = true;
        info.value = product;
    }, 550);
}

function pressCancel(): void {
    if (pressTimer) {
        clearTimeout(pressTimer);
        pressTimer = null;
    }
}

function pressEnd(product: Product): void {
    const wasPressing = pressTimer !== null;
    pressCancel();

    if (wasPressing && !longPressed) {
        pick(product);
    }
}

async function toggleSoldOut(product: Product): Promise<void> {
    await enqueue('product.soldOut', { productId: product.id, soldOut: !product.soldOut });
    info.value = null;
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="relative shrink-0 p-2">
            <Search class="pointer-events-none absolute top-1/2 left-5 size-5 -translate-y-1/2 text-slate-400" />
            <input
                v-model="query"
                type="search"
                :placeholder="t('order.searchProducts')"
                class="h-12 w-full rounded-xl border border-slate-200 bg-white pr-10 pl-11 text-base outline-none focus:border-[#00056a] focus:ring-2 focus:ring-[#00056a]/20"
            />
            <button v-if="query" type="button" class="absolute top-1/2 right-4 -translate-y-1/2 p-1 text-slate-400" @click="query = ''">
                <X class="size-5" />
            </button>
        </div>

        <div v-if="!query" class="flex shrink-0 gap-1.5 overflow-x-auto px-2 pb-2">
            <button
                v-if="frequentProducts.length"
                type="button"
                class="flex h-10 shrink-0 items-center gap-1 rounded-lg px-3 text-sm font-semibold"
                :class="tab === 'frequent' ? 'bg-[#00056a] text-white' : 'bg-white text-slate-700'"
                @click="selectTab('frequent')"
            >
                <Star class="size-4" /> {{ t('order.frequent') }}
            </button>
            <button
                v-if="menusToday.length"
                type="button"
                class="flex h-10 shrink-0 items-center gap-1 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white"
                @click="emit('menu')"
            >
                <UtensilsCrossed class="size-4" /> {{ t('order.setMenu') }}
            </button>
            <button
                v-for="category in roots"
                :key="category.id"
                type="button"
                class="h-10 shrink-0 rounded-lg border-b-4 px-3 text-sm font-semibold"
                :class="tab === `c${category.id}` ? 'bg-[#00056a] text-white' : 'bg-white text-slate-700'"
                :style="{ borderBottomColor: category.color || 'transparent' }"
                @click="selectTab(`c${category.id}`)"
            >
                {{ tr(category.name) }}
            </button>
        </div>

        <div v-if="children.length && !query" class="flex shrink-0 gap-1.5 overflow-x-auto px-2 pb-2">
            <button
                type="button"
                class="h-9 shrink-0 rounded-full px-3 text-sm"
                :class="subCategory === null ? 'bg-slate-800 text-white' : 'bg-white text-slate-600'"
                @click="subCategory = null"
            >
                {{ t('common.all') }}
            </button>
            <button
                v-for="category in children"
                :key="category.id"
                type="button"
                class="h-9 shrink-0 rounded-full px-3 text-sm"
                :class="subCategory === category.id ? 'bg-slate-800 text-white' : 'bg-white text-slate-600'"
                @click="subCategory = category.id"
            >
                {{ tr(category.name) }}
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
            <div class="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2">
                <button
                    v-for="product in products"
                    :key="product.id"
                    type="button"
                    class="relative flex h-28 flex-col overflow-hidden rounded-xl border-l-[6px] bg-white text-left shadow-sm transition active:scale-95"
                    :class="product.soldOut ? 'opacity-50' : ''"
                    :style="{ borderLeftColor: productColor(product) || '#cbd5e1' }"
                    @pointerdown="pressStart(product)"
                    @pointerup="pressEnd(product)"
                    @pointerleave="pressCancel"
                    @contextmenu.prevent="info = product"
                >
                    <img v-if="product.photoUrl" :src="product.photoUrl" alt="" class="absolute inset-0 size-full object-cover opacity-25" loading="lazy" />
                    <span class="relative line-clamp-3 flex-1 p-2 text-[13px] leading-tight font-semibold">{{ tr(product.name) }}</span>
                    <span class="relative flex items-end justify-between gap-1 px-2 pb-1.5">
                        <AllergenIcons :allergens="product.allergens" small />
                        <span class="text-sm font-bold text-[#00056a]">{{ formatMoney(product.price) }}</span>
                    </span>
                    <span v-if="product.soldOut" class="absolute inset-x-0 top-1/2 -translate-y-1/2 -rotate-6 bg-red-600 py-0.5 text-center text-xs font-bold text-white uppercase">
                        {{ t('order.soldOut') }}
                    </span>
                </button>
            </div>
            <p v-if="!products.length" class="py-10 text-center text-sm text-slate-500">{{ t('common.empty') }}</p>
        </div>

        <Dialog :open="!!info" @update:open="(v) => !v && (info = null)">
            <DialogContent v-if="info" class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ tr(info.name) }}</DialogTitle>
                    <DialogDescription>{{ formatMoney(info.price) }} · {{ t('common.vat') }} {{ info.vatRate }}%</DialogDescription>
                </DialogHeader>
                <img v-if="info.photoUrl" :src="info.photoUrl" alt="" class="max-h-56 w-full rounded-lg object-cover" />
                <div>
                    <p class="mb-2 text-sm font-semibold">{{ t('order.allergens') }}</p>
                    <AllergenIcons :allergens="info.allergens" with-labels />
                    <p v-if="!info.allergens.length" class="text-sm text-slate-500">—</p>
                </div>
                <DialogFooter class="gap-2">
                    <Button variant="outline" :class="info.soldOut ? '' : 'text-red-700'" @click="toggleSoldOut(info)">
                        <Ban class="size-4" />
                        {{ info.soldOut ? t('order.markAvailable') : t('order.markSoldOut') }}
                    </Button>
                    <Button v-if="!info.soldOut" @click="emit('pick', info); info = null">{{ t('order.addToOrder') }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
