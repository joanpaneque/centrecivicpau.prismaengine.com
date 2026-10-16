<script setup lang="ts">
import { ArrowLeft, Ban, PencilLine, Search, Star, UtensilsCrossed, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import AllergenIcons from '@/components/pos/AllergenIcons.vue';
import InventProductDialog from '@/components/pos/InventProductDialog.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { categoryColor, categoryProducts, frequentProducts, menusToday, NEUTRAL_TILE, productColor, readableText, searchProducts } from '@/pos/catalog';
import { formatMoney } from '@/pos/money';
import type { InventedProduct } from '@/pos/orders';
import { sorted, state } from '@/pos/store';
import { enqueue } from '@/pos/sync';
import type { Category, Product, SetMenu } from '@/pos/types';

const emit = defineEmits<{ pick: [product: Product]; menu: [menu: SetMenu]; invent: [product: InventedProduct] }>();

const FREQUENT = 'frequent';
/** The set menus available today, shown as one more family. */
const MENUS = 'menus';

const query = ref('');
/** null: the families grid; 'frequent', 'menus' or a root category id: what is inside. */
const view = ref<typeof FREQUENT | typeof MENUS | number | null>(null);
const subCategory = ref<number | null>(null);
const info = ref<Product | null>(null);
const inventing = ref(false);

const roots = computed(() => sorted.categories.value.filter((c) => !c.parentId));
const current = computed(() => (typeof view.value === 'number' ? (state.categories[view.value] ?? null) : null));
const children = computed(() => sorted.categories.value.filter((c) => current.value !== null && c.parentId === current.value.id));

const products = computed(() => {
    if (query.value.trim()) {
        return searchProducts(query.value);
    }

    if (view.value === FREQUENT) {
        return frequentProducts.value;
    }

    if (current.value) {
        return categoryProducts(subCategory.value ?? current.value.id);
    }

    return [];
});

const showFamilies = computed(() => !query.value.trim() && view.value === null);

function openFamily(value: typeof FREQUENT | typeof MENUS | number): void {
    view.value = value;
    subCategory.value = null;
}

function backToFamilies(): void {
    view.value = null;
    subCategory.value = null;
}

function tileColor(product: Product): string {
    return productColor(product) ?? NEUTRAL_TILE;
}

function familyColor(category: Category): string {
    return categoryColor(category) ?? NEUTRAL_TILE;
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
        <div class="flex shrink-0 items-center gap-2 p-2">
            <div class="relative min-w-0 flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="query"
                    type="search"
                    :placeholder="t('order.searchProducts')"
                    class="h-12 w-full rounded-xl border border-slate-200 bg-white pr-10 pl-11 text-base outline-none focus:border-[#00056a] focus:ring-2 focus:ring-[#00056a]/20"
                />
                <button v-if="query" type="button" class="absolute top-1/2 right-2 -translate-y-1/2 p-1 text-slate-400" @click="query = ''">
                    <X class="size-5" />
                </button>
            </div>
            <button type="button" class="flex h-12 shrink-0 items-center gap-1 rounded-xl bg-amber-500 px-3 text-sm font-semibold text-amber-950" @click="inventing = true">
                <PencilLine class="size-4" /> <span class="max-sm:hidden">{{ t('order.invent') }}</span>
            </button>
        </div>

        <!-- Families first -->
        <div v-if="showFamilies" class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
            <div class="grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] gap-2">
                <button
                    v-if="frequentProducts.length"
                    type="button"
                    class="flex h-24 items-center gap-3 rounded-2xl bg-[#00056a] p-3 text-left text-white shadow-sm transition active:scale-95"
                    @click="openFamily(FREQUENT)"
                >
                    <Star class="size-10 shrink-0" />
                    <span class="line-clamp-2 text-lg leading-tight font-bold">{{ t('order.frequent') }}</span>
                </button>
                <button
                    v-if="menusToday.length"
                    type="button"
                    class="flex h-24 items-center gap-3 rounded-2xl bg-emerald-600 p-3 text-left text-white shadow-sm transition active:scale-95"
                    @click="openFamily(MENUS)"
                >
                    <UtensilsCrossed class="size-10 shrink-0" />
                    <span class="min-w-0">
                        <span class="line-clamp-2 text-lg leading-tight font-bold">{{ t('order.menusFamily') }}</span>
                        <span class="text-xs opacity-90">{{ t('order.menusCount', { count: menusToday.length }) }}</span>
                    </span>
                </button>
                <button
                    v-for="category in roots"
                    :key="category.id"
                    type="button"
                    class="flex h-24 items-center gap-3 rounded-2xl p-3 text-left shadow-sm transition active:scale-95"
                    :style="{ background: familyColor(category), color: readableText(familyColor(category)) }"
                    @click="openFamily(category.id)"
                >
                    <img v-if="category.photoUrl" :src="category.photoUrl" alt="" class="size-16 shrink-0 rounded-xl bg-white/20 object-cover" loading="lazy" />
                    <span class="line-clamp-3 text-lg leading-tight font-bold">{{ tr(category.name) }}</span>
                </button>
            </div>
            <p v-if="!roots.length && !frequentProducts.length" class="py-10 text-center text-sm text-slate-500">{{ t('common.empty') }}</p>
        </div>

        <template v-else>
            <div v-if="!query.trim()" class="flex shrink-0 items-center gap-2 px-2 pb-2">
                <button type="button" class="flex h-11 shrink-0 items-center gap-1 rounded-xl bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm" @click="backToFamilies">
                    <ArrowLeft class="size-5" /> {{ t('order.families') }}
                </button>
                <span
                    class="flex h-11 min-w-0 items-center gap-2 truncate rounded-xl px-3 text-base font-bold"
                    :style="current ? { background: familyColor(current), color: readableText(familyColor(current)) } : { background: view === MENUS ? '#059669' : '#00056a', color: '#fff' }"
                >
                    <img v-if="current?.photoUrl" :src="current.photoUrl" alt="" class="size-8 rounded-lg object-cover" />
                    {{ current ? tr(current.name) : view === MENUS ? t('order.menusFamily') : t('order.frequent') }}
                </span>
            </div>

            <div v-if="children.length && !query.trim()" class="flex shrink-0 gap-1.5 overflow-x-auto px-2 pb-2">
                <button
                    type="button"
                    class="h-10 shrink-0 rounded-full px-4 text-sm font-semibold"
                    :class="subCategory === null ? 'bg-slate-800 text-white' : 'bg-white text-slate-600'"
                    @click="subCategory = null"
                >
                    {{ t('common.all') }}
                </button>
                <button
                    v-for="category in children"
                    :key="category.id"
                    type="button"
                    class="flex h-10 shrink-0 items-center gap-1.5 rounded-full px-4 text-sm font-semibold"
                    :class="subCategory === category.id ? 'ring-4 ring-slate-800/70' : ''"
                    :style="{ background: familyColor(category), color: readableText(familyColor(category)) }"
                    @click="subCategory = category.id"
                >
                    <img v-if="category.photoUrl" :src="category.photoUrl" alt="" class="size-6 rounded-full object-cover" />
                    {{ tr(category.name) }}
                </button>
            </div>

            <!-- The set menus of today: tap one to choose its dishes -->
            <div v-if="view === MENUS && !query.trim()" class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                <div class="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-2">
                    <button
                        v-for="menu in menusToday"
                        :key="menu.id"
                        type="button"
                        class="flex min-h-32 flex-col rounded-2xl p-3 text-left shadow-sm transition active:scale-95"
                        :style="{ background: menu.color || '#059669', color: readableText(menu.color || '#059669') }"
                        @click="emit('menu', menu)"
                    >
                        <span class="line-clamp-2 text-lg leading-tight font-bold">{{ tr(menu.name) }}</span>
                        <span v-if="menu.includes" class="mt-1 line-clamp-2 text-xs opacity-90">{{ tr(menu.includes) }}</span>
                        <span class="mt-auto pt-2 text-xl font-bold">{{ formatMoney(menu.price) }}</span>
                    </button>
                </div>
            </div>

            <div v-else class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                <div class="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2">
                    <button
                        v-for="product in products"
                        :key="product.id"
                        type="button"
                        class="relative flex h-28 flex-col overflow-hidden rounded-xl text-left shadow-sm transition active:scale-95"
                        :class="product.soldOut ? 'opacity-50' : ''"
                        :style="{ background: tileColor(product), color: product.photoUrl ? '#ffffff' : readableText(tileColor(product)) }"
                        @pointerdown="pressStart(product)"
                        @pointerup="pressEnd(product)"
                        @pointerleave="pressCancel"
                        @contextmenu.prevent="info = product"
                    >
                        <template v-if="product.photoUrl">
                            <img :src="product.photoUrl" alt="" class="absolute inset-0 size-full object-cover" loading="lazy" />
                            <span class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/45 to-black/10" />
                        </template>
                        <span class="relative line-clamp-3 flex-1 p-2 text-[15px] leading-tight font-bold">{{ tr(product.name) }}</span>
                        <span class="relative flex items-end justify-between gap-1 px-2 pb-1.5">
                            <AllergenIcons :allergens="product.allergens" small />
                            <span v-if="product.price !== null" class="text-sm font-bold">{{ formatMoney(product.price) }}</span>
                            <span v-else class="rounded bg-black/25 px-1 text-[11px] font-semibold text-white">{{ t('order.unpriced') }}</span>
                        </span>
                        <span v-if="product.soldOut" class="absolute inset-x-0 top-1/2 -translate-y-1/2 -rotate-6 bg-red-600 py-0.5 text-center text-xs font-bold text-white uppercase">
                            {{ t('order.soldOut') }}
                        </span>
                    </button>
                </div>
                <p v-if="!products.length" class="py-10 text-center text-sm text-slate-500">{{ t('common.empty') }}</p>
            </div>
        </template>

        <Dialog :open="!!info" @update:open="(v) => !v && (info = null)">
            <DialogContent v-if="info" class="max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ tr(info.name) }}</DialogTitle>
                    <DialogDescription>{{ info.price !== null ? formatMoney(info.price) : t('order.unpriced') }} · {{ t('common.vat') }} {{ info.vatRate }}%</DialogDescription>
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
        <InventProductDialog v-if="inventing" @add="emit('invent', $event)" @created="emit('pick', $event)" @close="inventing = false" />
    </div>
</template>
