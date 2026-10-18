<script setup lang="ts">
import { ArrowLeft, Ban, Check, Move, PencilLine, Plus, Search, Star, UtensilsCrossed, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import AllergenIcons from '@/components/pos/AllergenIcons.vue';
import CatalogFamilyDialog from '@/components/pos/CatalogFamilyDialog.vue';
import CatalogProductDialog from '@/components/pos/CatalogProductDialog.vue';
import InventProductDialog from '@/components/pos/InventProductDialog.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t, tr } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { categoryColor, categoryProducts, frequentProducts, menusToday, NEUTRAL_TILE, productColor, readableText, searchProducts } from '@/pos/catalog';
import { formatMoney } from '@/pos/money';
import type { InventedProduct } from '@/pos/orders';
import { sorted, state, upsert } from '@/pos/store';
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
    if (moving.value) {
        if (moving.value.step === 'pick') {
            chooseProduct(product);
        }

        return;
    }

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
        if (moving.value) {
            return;
        }

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

/**
 * The product is picked on click, not when the finger lifts: the options window opens in
 * that moment, and picking on pointerup let the same tap's click land on the option that
 * happened to be under the finger (it looked like an option was chosen at random).
 */
function tap(product: Product): void {
    pressCancel();

    if (longPressed) {
        longPressed = false;

        return;
    }

    pick(product);
}

// ------------------------------------------------------------ Move products and families
// Step 1: tap what to move (a product, or «Mou» on a family). Step 2: go where it should be
// and tap «Posa-ho aquí». Admins only, like editing the floor.

type Moving = { step: 'pick' } | { step: 'place'; kind: 'product' | 'family'; id: number; name: string };

const canMove = computed(() => state.me?.role === 'admin');
/** Admins also create families and products right here (the tiles with a +). */
const canCreate = computed(() => canMove.value && !moving.value);
const creatingFamily = ref<{ parentId: number | null } | null>(null);
const creatingProduct = ref<{ categoryId: number | null } | null>(null);
const moving = ref<Moving | null>(null);
const placing = ref(false);

function startMove(): void {
    moving.value = { step: 'pick' };
    query.value = '';
}

function cancelMove(): void {
    moving.value = null;
}

function chooseProduct(product: Product): void {
    moving.value = { step: 'place', kind: 'product', id: product.id, name: tr(product.name) };
}

function chooseFamily(category: Category): void {
    moving.value = { step: 'place', kind: 'family', id: category.id, name: tr(category.name) };
    backToFamilies();
}

/** The family shown right now (the subfamily if one is open). */
const here = computed(() => (current.value ? (subCategory.value ? (state.categories[subCategory.value] ?? current.value) : current.value) : null));
const hereLabel = computed(() => {
    if (showFamilies.value) {
        return t('order.catalogMove.mainFamilies');
    }

    if (!here.value || !current.value) {
        return '—';
    }

    return here.value.id === current.value.id ? tr(current.value.name) : `${tr(current.value.name)} › ${tr(here.value.name)}`;
});

/** Where «Posa-ho aquí» would put it, or why not here. */
const placement = computed<{ targetId: number | null; label: string } | { problem: string } | null>(() => {
    const m = moving.value;

    if (!m || m.step !== 'place') {
        return null;
    }

    if (query.value.trim()) {
        return { problem: t('order.catalogMove.clearSearch') };
    }

    if (m.kind === 'product') {
        if (!here.value) {
            return { problem: t('order.catalogMove.openFamily') };
        }

        if (state.products[m.id]?.categoryId === here.value.id) {
            return { problem: t('order.catalogMove.alreadyHere') };
        }

        return { targetId: here.value.id, label: hereLabel.value };
    }

    const family = state.categories[m.id];

    if (showFamilies.value) {
        return family?.parentId ? { targetId: null, label: hereLabel.value } : { problem: t('order.catalogMove.alreadyHere') };
    }

    if (!current.value) {
        return { problem: t('order.catalogMove.openFamily') };
    }

    if (current.value.id === m.id) {
        return { problem: t('order.catalogMove.notInside') };
    }

    if (sorted.categories.value.some((c) => c.parentId === m.id)) {
        return { problem: t('order.catalogMove.hasChildren') };
    }

    if (family?.parentId === current.value.id) {
        return { problem: t('order.catalogMove.alreadyHere') };
    }

    return { targetId: current.value.id, label: tr(current.value.name) };
});

async function placeHere(): Promise<void> {
    const m = moving.value;
    const target = placement.value;

    if (!m || m.step !== 'place' || !target || 'problem' in target || placing.value) {
        return;
    }

    placing.value = true;

    try {
        const response = await api<{ product?: Product; category?: Category }>('POST', '/tpv/api/catalog/move', { kind: m.kind, id: m.id, targetId: target.targetId });

        if (response.product) {
            upsert('products', response.product);
        }

        if (response.category) {
            upsert('categories', response.category);
        }

        toast.success(t('order.catalogMove.done', { name: m.name, where: target.label }));
        moving.value = null;
    } catch (error) {
        toast.error(error instanceof HttpError && error.status === 422 ? error.message : t('common.offlineOnly'));
    } finally {
        placing.value = false;
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
            <button
                v-if="!moving"
                type="button"
                class="flex h-12 shrink-0 items-center gap-1 rounded-xl bg-amber-500 px-3 text-sm font-semibold text-amber-950"
                @click="inventing = true"
            >
                <PencilLine class="size-4" /> <span class="max-sm:hidden">{{ t('order.invent') }}</span>
            </button>
            <button
                v-if="canMove && !moving"
                type="button"
                class="flex h-12 shrink-0 items-center gap-1 rounded-xl bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm"
                :title="t('order.catalogMove.button')"
                @click="startMove"
            >
                <Move class="size-4" /> <span class="max-lg:hidden">{{ t('order.catalogMove.button') }}</span>
            </button>
        </div>

        <!-- Moving: what to move, then where -->
        <div v-if="moving" class="mx-2 mb-2 flex shrink-0 flex-wrap items-center gap-2 rounded-xl bg-violet-600 p-3 text-white shadow">
            <Move class="size-6 shrink-0" />
            <div class="min-w-0 flex-1 basis-56">
                <template v-if="moving.step === 'pick'">
                    <p class="font-bold">{{ t('order.catalogMove.whatTitle') }}</p>
                    <p class="text-sm opacity-90">{{ t('order.catalogMove.whatHelp') }}</p>
                </template>
                <template v-else>
                    <p class="font-bold">{{ t('order.catalogMove.whereTitle', { name: moving.name }) }}</p>
                    <p class="text-sm opacity-90">
                        <template v-if="placement && 'problem' in placement">{{ placement.problem }}</template>
                        <template v-else>{{ t('order.catalogMove.whereHelp', { where: placement?.label ?? '' }) }}</template>
                    </p>
                </template>
            </div>
            <button type="button" class="h-11 shrink-0 rounded-lg bg-white/15 px-4 text-sm font-semibold" @click="cancelMove">{{ t('common.cancel') }}</button>
            <button
                v-if="moving.step === 'place'"
                type="button"
                class="flex h-11 shrink-0 items-center gap-1.5 rounded-lg bg-white px-4 text-sm font-bold text-violet-700 disabled:opacity-50"
                :disabled="!placement || 'problem' in placement || placing"
                @click="placeHere"
            >
                <Check class="size-5" /> {{ placing ? t('common.saving') : t('order.catalogMove.placeHere') }}
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
                <div v-for="category in roots" :key="category.id" class="relative">
                    <button
                        type="button"
                        class="flex h-24 w-full items-center gap-3 rounded-2xl p-3 text-left shadow-sm transition active:scale-95"
                        :class="moving?.step === 'place' && moving.kind === 'family' && moving.id === category.id ? 'ring-4 ring-violet-500' : ''"
                        :style="{ background: familyColor(category), color: readableText(familyColor(category)) }"
                        @click="openFamily(category.id)"
                    >
                        <img v-if="category.photoUrl" :src="category.photoUrl" alt="" class="size-16 shrink-0 rounded-xl bg-white/20 object-cover" loading="lazy" />
                        <span class="line-clamp-3 text-lg leading-tight font-bold" :class="moving?.step === 'pick' ? 'pr-14' : ''">{{ tr(category.name) }}</span>
                    </button>
                    <button
                        v-if="moving?.step === 'pick'"
                        type="button"
                        class="absolute top-1.5 right-1.5 flex h-9 items-center gap-1 rounded-lg bg-violet-600 px-2 text-xs font-bold text-white shadow"
                        @click="chooseFamily(category)"
                    >
                        <Move class="size-4" /> {{ t('order.catalogMove.this') }}
                    </button>
                </div>
                <button
                    v-if="canCreate"
                    type="button"
                    class="flex h-24 items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 text-base font-semibold text-slate-500 transition active:scale-95"
                    @click="creatingFamily = { parentId: null }"
                >
                    <Plus class="size-6" /> {{ t('order.catalogCreate.newFamily') }}
                </button>
            </div>
            <p v-if="!roots.length && !frequentProducts.length && !canCreate" class="py-10 text-center text-sm text-slate-500">{{ t('common.empty') }}</p>
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
                <button
                    v-if="moving?.step === 'pick' && here"
                    type="button"
                    class="ml-auto flex h-11 shrink-0 items-center gap-1 rounded-xl bg-violet-600 px-3 text-sm font-bold text-white shadow-sm"
                    @click="chooseFamily(here)"
                >
                    <Move class="size-4" /> {{ t('order.catalogMove.thisFamily', { name: tr(here.name) }) }}
                </button>
            </div>

            <div v-if="(children.length || (canCreate && current)) && !query.trim()" class="flex shrink-0 gap-1.5 overflow-x-auto px-2 pb-2">
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
                <button
                    v-if="canCreate && current"
                    type="button"
                    class="flex h-10 shrink-0 items-center gap-1 rounded-full border-2 border-dashed border-slate-300 px-4 text-sm font-semibold text-slate-500"
                    @click="creatingFamily = { parentId: current.id }"
                >
                    <Plus class="size-4" /> {{ t('order.catalogCreate.subfamily') }}
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
                        :class="[product.soldOut ? 'opacity-50' : '', moving?.step === 'place' && moving.kind === 'product' && moving.id === product.id ? 'ring-4 ring-violet-500' : '']"
                        :style="{ background: tileColor(product), color: product.photoUrl ? '#ffffff' : readableText(tileColor(product)) }"
                        @pointerdown="pressStart(product)"
                        @pointerup="pressCancel"
                        @pointerleave="pressCancel"
                        @click="tap(product)"
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
                    <button
                        v-if="canCreate && here && !query.trim()"
                        type="button"
                        class="flex h-28 flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-slate-300 text-sm font-semibold text-slate-500 transition active:scale-95"
                        @click="creatingProduct = { categoryId: here.id }"
                    >
                        <Plus class="size-6" /> {{ t('order.catalogCreate.newProduct') }}
                    </button>
                </div>
                <p v-if="!products.length && !(canCreate && here)" class="py-10 text-center text-sm text-slate-500">{{ t('common.empty') }}</p>
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
        <CatalogFamilyDialog v-if="creatingFamily" :parent-id="creatingFamily.parentId" @close="creatingFamily = null" />
        <CatalogProductDialog v-if="creatingProduct" :category-id="creatingProduct.categoryId" @close="creatingProduct = null" />
        <InventProductDialog v-if="inventing" @add="emit('invent', $event)" @created="emit('pick', $event)" @close="inventing = false" />
    </div>
</template>
