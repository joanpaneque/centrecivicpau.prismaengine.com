<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Copy, GripVertical, ImageOff, Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Field from '@/components/admin/Field.vue';
import ImageCropper from '@/components/admin/ImageCropper.vue';
import type { CropRect } from '@/components/admin/ImageCropper.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import TransInput from '@/components/admin/TransInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import { centsToInput, money, parseMoney, toTrans } from '@/lib/format';
import { catalog as catalogRoute } from '@/routes/admin';
import * as categoriesRoutes from '@/routes/admin/categories';
import * as groupRoutes from '@/routes/admin/modifier-groups';
import * as productRoutes from '@/routes/admin/products';

type Trans = Record<string, string>;
type Category = {
    id: number;
    parentId: number | null;
    name: Trans;
    color: string | null;
    photoUrl: string | null;
    destinationId: number | null;
    isMenu: boolean;
    active: boolean;
    productsCount: number;
    modifierGroupIds: number[];
};
type Product = {
    id: number;
    categoryId: number;
    name: Trans;
    price: number | null;
    vatRate: number;
    photoUrl: string | null;
    color: string | null;
    allergens: string[];
    destinationId: number | null;
    active: boolean;
    soldOut: boolean;
    orderCount: number;
    modifierGroupIds: number[];
};
type ModifierGroup = {
    id: number;
    name: Trans;
    multiple: boolean;
    required: boolean;
    modifiers: { id: number; name: Trans; priceDelta: number }[];
};
type Destination = { id: number; name: Trans | string; code: string };

const props = defineProps<{
    categories: Category[];
    products: Product[];
    modifierGroups: ModifierGroup[];
    destinations: Destination[];
    allergens: string[];
}>();

const { t, tr } = useI18n();
const tab = ref<'products' | 'modifiers'>('products');
// ?familia=ID (from Gestió → Impressió) opens that family to edit it.
const linkedFamily = Number(new URLSearchParams(window.location.search).get('familia')) || null;
const selectedCategory = ref<number | null>(props.categories.find((c) => c.id === linkedFamily)?.id ?? props.categories[0]?.id ?? null);
const search = ref('');

const tree = computed(() => {
    const roots = props.categories.filter((c) => c.parentId === null);

    return roots.flatMap((root) => [{ ...root, depth: 0 }, ...props.categories.filter((c) => c.parentId === root.id).map((c) => ({ ...c, depth: 1 }))]);
});

const categoryList = ref<(Category & { depth: number })[]>([]);
watch(tree, (value) => (categoryList.value = [...value]), { immediate: true });

const productList = ref<Product[]>([]);
watch(
    [() => props.products, selectedCategory, search],
    () => {
        const term = search.value.trim().toLowerCase();
        productList.value = props.products.filter((p) =>
            term ? `${p.name.ca ?? ''} ${p.name.es ?? ''}`.toLowerCase().includes(term) : p.categoryId === selectedCategory.value,
        );
    },
    { immediate: true },
);

const destinationOptions = computed(() => props.destinations.map((d) => ({ value: d.id as number | null, label: tr(d.name) })));

/** Where a family prints: its own destination or the closest parent's. */
function familyDestination(id: number | null): number | null {
    let family = id ? props.categories.find((c) => c.id === id) : undefined;

    for (let guard = 0; family && guard < 10; guard++) {
        if (family.destinationId) {
            return family.destinationId;
        }

        family = family.parentId ? props.categories.find((c) => c.id === family!.parentId) : undefined;
    }

    return null;
}

const inheritedName = (id: number | null) => {
    const destination = props.destinations.find((d) => d.id === id);

    return destination ? tr(destination.name) : t('admin.printing.routing.nowhere');
};
const categoryOptions = computed(() => tree.value.map((c) => ({ value: c.id as number | null, label: `${c.depth ? '— ' : ''}${tr(c.name)}` })));
const vatOptions = [0, 4, 5, 10, 21].map((rate) => ({ value: rate, label: `${rate}%` }));

function saveCategoryOrder(): void {
    router.post(categoriesRoutes.reorder.url(), { ids: categoryList.value.map((c) => c.id) }, { preserveScroll: true, preserveState: true });
}

function saveProductOrder(): void {
    if (!search.value) {
        router.post(productRoutes.reorder.url(), { ids: productList.value.map((p) => p.id) }, { preserveScroll: true, preserveState: true });
    }
}

const categoryOpen = ref(false);
const editingCategory = ref<Category | null>(null);
const categoryForm = useForm({
    name: { ca: '', es: '' },
    parentId: null as number | null,
    color: '#00056a',
    destinationId: null as number | null,
    isMenu: false,
    active: true,
    modifierGroupIds: [] as number[],
    removePhoto: false,
});
const categoryPhoto = ref<File | null>(null);
const categoryPhotoPreview = computed(() => (categoryPhoto.value ? URL.createObjectURL(categoryPhoto.value) : null));

function pickCategoryPhoto(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file) {
        categoryPhoto.value = file;
        categoryForm.removePhoto = false;
    }
}

function openCategory(category: Category | null): void {
    editingCategory.value = category;
    categoryForm.clearErrors();
    categoryForm.name = toTrans(category?.name);
    categoryForm.parentId = category?.parentId ?? null;
    categoryForm.color = category?.color ?? '#00056a';
    categoryForm.destinationId = category?.destinationId ?? null;
    categoryForm.isMenu = category?.isMenu ?? false;
    categoryForm.active = category?.active ?? true;
    categoryForm.modifierGroupIds = [...(category?.modifierGroupIds ?? [])];
    categoryForm.removePhoto = false;
    categoryPhoto.value = null;
    categoryOpen.value = true;
}

onMounted(() => {
    const family = props.categories.find((c) => c.id === linkedFamily);

    if (family) {
        openCategory(family);
    }
});

function saveCategory(): void {
    categoryForm
        .transform((data) => ({ ...data, photo: categoryPhoto.value }))
        .post(editingCategory.value ? categoriesRoutes.update.url(editingCategory.value.id) : categoriesRoutes.store.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => (categoryOpen.value = false),
        });
}

const productOpen = ref(false);
const editingProduct = ref<Product | null>(null);
const photoFile = ref<File | null>(null);
const crop = ref<CropRect | null>(null);
const priceInput = ref('');
const productForm = useForm({
    name: { ca: '', es: '' },
    categoryId: null as number | null,
    price: 0,
    vatRate: 10,
    color: '' as string,
    allergens: [] as string[],
    destinationId: null as number | null,
    active: true,
    soldOut: false,
    modifierGroupIds: [] as number[],
    removePhoto: false,
});

function openProduct(product: Product | null): void {
    editingProduct.value = product;
    productForm.clearErrors();
    productForm.name = toTrans(product?.name);
    productForm.categoryId = product?.categoryId ?? selectedCategory.value;
    priceInput.value = centsToInput(product?.price ?? null);
    productForm.vatRate = product?.vatRate ?? 10;
    productForm.color = product?.color ?? '';
    productForm.allergens = [...(product?.allergens ?? [])];
    productForm.destinationId = product?.destinationId ?? null;
    productForm.active = product?.active ?? true;
    productForm.soldOut = product?.soldOut ?? false;
    productForm.modifierGroupIds = [...(product?.modifierGroupIds ?? [])];
    productForm.removePhoto = false;
    photoFile.value = null;
    crop.value = null;
    productOpen.value = true;
}

function pickPhoto(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file) {
        photoFile.value = file;
        productForm.removePhoto = false;
    }
}

function saveProduct(): void {
    productForm
        .transform((data) => ({
            ...data,
            price: priceInput.value.trim() === '' ? null : parseMoney(priceInput.value),
            color: data.color || null,
            photo: photoFile.value,
            crop: photoFile.value ? crop.value : null,
        }))
        .post(editingProduct.value ? productRoutes.update.url(editingProduct.value.id) : productRoutes.store.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => (productOpen.value = false),
        });
}

function toggle(list: number[] | string[], value: number | string): void {
    const array = list as (number | string)[];
    const index = array.indexOf(value);

    if (index >= 0) {
        array.splice(index, 1);
    } else {
        array.push(value);
    }
}

function duplicate(product: Product): void {
    router.post(productRoutes.duplicate.url(product.id), {}, { preserveScroll: true });
}

const groupOpen = ref(false);
const editingGroup = ref<ModifierGroup | null>(null);
const groupForm = useForm({
    name: { ca: '', es: '' },
    multiple: true,
    required: false,
    modifiers: [] as { key: string; id: number | null; name: { ca: string; es: string }; price: string }[],
    categoryIds: [] as number[],
    productIds: [] as number[],
});

let newModifierKey = 0;

function addModifier(): void {
    newModifierKey++;
    groupForm.modifiers.push({ key: `new${newModifierKey}`, id: null, name: { ca: '', es: '' }, price: '' });
}

/** The options keep the order of this list (also in the TPV): drag them or use the arrows. */
function moveModifier(index: number, by: -1 | 1): void {
    const list = groupForm.modifiers;
    const to = index + by;

    if (to < 0 || to >= list.length) {
        return;
    }

    [list[index], list[to]] = [list[to], list[index]];
}

function openGroup(group: ModifierGroup | null): void {
    editingGroup.value = group;
    groupForm.clearErrors();
    groupForm.name = toTrans(group?.name);
    groupForm.multiple = group?.multiple ?? true;
    groupForm.required = group?.required ?? false;
    groupForm.modifiers = (group?.modifiers ?? []).map((m) => ({ key: `m${m.id}`, id: m.id, name: toTrans(m.name), price: centsToInput(m.priceDelta) }));
    groupForm.categoryIds = props.categories.filter((c) => group && c.modifierGroupIds.includes(group.id)).map((c) => c.id);
    groupForm.productIds = props.products.filter((p) => group && p.modifierGroupIds.includes(group.id)).map((p) => p.id);
    groupOpen.value = true;
}

function saveGroup(): void {
    groupForm.transform((data) => ({
        ...data,
        modifiers: data.modifiers.map((m) => ({ id: m.id, name: m.name, priceDelta: parseMoney(m.price) })),
    }));

    const options = { preserveScroll: true, onSuccess: () => (groupOpen.value = false) };

    if (editingGroup.value) {
        groupForm.patch(groupRoutes.update.url(editingGroup.value.id), options);
    } else {
        groupForm.post(groupRoutes.store.url(), options);
    }
}

const deleting = ref<{ kind: 'category' | 'product' | 'group'; id: number; label: string } | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function confirmDelete(): void {
    const target = deleting.value;

    if (!target) {
        return;
    }

    const url =
        target.kind === 'category'
            ? categoriesRoutes.destroy.url(target.id)
            : target.kind === 'product'
              ? productRoutes.destroy.url(target.id)
              : groupRoutes.destroy.url(target.id);

    router.delete(url, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
            productOpen.value = false;
            categoryOpen.value = false;
            groupOpen.value = false;
        },
    });
}

const productSearch = ref('');
const filteredProductsForGroup = computed(() => {
    const term = productSearch.value.toLowerCase();

    return props.products.filter((p) => !term || tr(p.name).toLowerCase().includes(term)).slice(0, 80);
});

defineOptions({ layout: { breadcrumbs: [{ title: 'Carta', href: catalogRoute() }] } });
</script>

<template>
    <Head :title="t('admin.catalog.title')" />
    <PageHeader :title="t('admin.catalog.title')" :description="t('admin.catalog.description')">
        <div class="flex rounded-lg border p-0.5">
            <Button size="sm" :variant="tab === 'products' ? 'default' : 'ghost'" @click="tab = 'products'">
                {{ t('admin.catalog.products') }}
            </Button>
            <Button size="sm" :variant="tab === 'modifiers' ? 'default' : 'ghost'" @click="tab = 'modifiers'">
                {{ t('admin.catalog.modifierGroups') }}
            </Button>
        </div>
    </PageHeader>

    <div v-if="tab === 'products'" class="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <aside class="space-y-2">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium">{{ t('admin.catalog.categories') }}</h2>
                <Button size="sm" variant="outline" @click="openCategory(null)"><Plus class="size-4" /> {{ t('admin.catalog.newCategory') }}</Button>
            </div>
            <VueDraggable v-model="categoryList" handle=".handle" :animation="150" class="space-y-1" @end="saveCategoryOrder">
                <div
                    v-for="category in categoryList"
                    :key="category.id"
                    class="group flex cursor-pointer items-center gap-2 rounded-lg border px-2 py-2 text-sm"
                    :class="[
                        selectedCategory === category.id && !search ? 'border-primary bg-primary/5' : 'bg-card hover:bg-muted/50',
                        { 'ml-5': category.depth === 1, 'opacity-50': !category.active },
                    ]"
                    @click="
                        selectedCategory = category.id;
                        search = '';
                    "
                >
                    <GripVertical class="handle size-4 shrink-0 cursor-grab text-muted-foreground" />
                    <span class="size-3 shrink-0 rounded-full" :style="{ background: category.color ?? '#cbd5e1' }" />
                    <span class="flex-1 truncate">{{ tr(category.name) }}</span>
                    <span class="text-xs text-muted-foreground">{{ category.productsCount }}</span>
                    <button
                        type="button"
                        class="-my-1 flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                        :title="t('common.edit')"
                        @click.stop="openCategory(category)"
                    >
                        <Pencil class="size-4" />
                    </button>
                </div>
            </VueDraggable>
            <p class="text-xs text-muted-foreground">{{ t('admin.catalog.sortHelp') }}</p>
        </aside>

        <section>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="relative max-w-xs flex-1">
                    <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                    <Input v-model="search" :placeholder="t('common.search')" class="pl-8" />
                </div>
                <Button :disabled="categories.length === 0" @click="openProduct(null)">
                    <Plus class="size-4" /> {{ t('admin.catalog.newProduct') }}
                </Button>
            </div>

            <VueDraggable
                v-model="productList"
                handle=".handle"
                :animation="150"
                :disabled="!!search"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                @end="saveProductOrder"
            >
                <div
                    v-for="product in productList"
                    :key="product.id"
                    class="flex gap-3 rounded-xl border bg-card p-3"
                    :class="{ 'opacity-50': !product.active }"
                >
                    <div class="relative size-16 shrink-0 overflow-hidden rounded-lg bg-muted" :style="{ background: product.photoUrl ? undefined : product.color ?? undefined }">
                        <img v-if="product.photoUrl" :src="product.photoUrl" alt="" class="size-full object-cover" />
                        <GripVertical v-if="!search" class="handle absolute top-1 left-1 size-4 cursor-grab rounded bg-white/70 text-muted-foreground" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ tr(product.name) }}</div>
                        <div class="text-sm tabular-nums">
                            <span v-if="product.price !== null">{{ money(product.price) }}</span>
                            <Badge v-else variant="outline" class="border-amber-400 text-amber-700">{{ t('admin.catalog.noPrice') }}</Badge>
                            <span class="text-xs text-muted-foreground">· IVA {{ product.vatRate }}%</span></div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <Badge v-if="product.soldOut" variant="destructive">{{ t('admin.catalog.soldOut') }}</Badge>
                            <Badge v-if="product.orderCount" variant="secondary">{{ product.orderCount }} {{ t('admin.catalog.sold').toLowerCase() }}</Badge>
                        </div>
                    </div>
                    <div class="flex flex-col gap-1">
                        <Button variant="ghost" size="icon" class="size-7" @click="openProduct(product)"><Pencil class="size-3.5" /></Button>
                        <Button variant="ghost" size="icon" class="size-7" :title="t('common.duplicate')" @click="duplicate(product)"><Copy class="size-3.5" /></Button>
                    </div>
                </div>
            </VueDraggable>
            <p v-if="productList.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
        </section>
    </div>

    <div v-else class="space-y-3">
        <Button @click="openGroup(null)"><Plus class="size-4" /> {{ t('admin.catalog.newModifierGroup') }}</Button>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="group in modifierGroups" :key="group.id" class="rounded-xl border bg-card p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="font-medium">{{ tr(group.name) }}</div>
                        <div class="mt-1 flex gap-1">
                            <Badge variant="secondary">{{ group.multiple ? t('admin.catalog.multiple') : t('order.chooseOne') }}</Badge>
                            <Badge v-if="group.required">{{ t('admin.catalog.required') }}</Badge>
                        </div>
                    </div>
                    <Button variant="ghost" size="icon" @click="openGroup(group)"><Pencil class="size-4" /></Button>
                </div>
                <ul class="mt-3 space-y-1 text-sm">
                    <li v-for="modifier in group.modifiers" :key="modifier.id" class="flex justify-between">
                        <span>{{ tr(modifier.name) }}</span>
                        <span v-if="modifier.priceDelta" class="text-muted-foreground tabular-nums">+{{ money(modifier.priceDelta) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <Dialog v-model:open="categoryOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <form class="space-y-4" @submit.prevent="saveCategory">
                <DialogHeader>
                    <DialogTitle>{{ editingCategory ? t('common.edit') : t('admin.catalog.newCategory') }}</DialogTitle>
                </DialogHeader>
                <TransInput v-model="categoryForm.name" :label="t('common.name')" :error="categoryForm.errors['name.ca'] || categoryForm.errors.name" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="t('admin.catalog.parent')" :error="categoryForm.errors.parentId">
                        <SelectInput
                            v-model="categoryForm.parentId"
                            :placeholder="t('admin.catalog.noParent')"
                            :options="categoryOptions.filter((o) => o.value !== editingCategory?.id && !o.label.startsWith('—'))"
                        />
                    </Field>
                    <Field :label="t('admin.catalog.destination')">
                        <SelectInput
                            v-model="categoryForm.destinationId"
                            :placeholder="categoryForm.parentId ? t('admin.printing.routing.likeParent', { name: inheritedName(familyDestination(categoryForm.parentId)) }) : t('admin.printing.routing.nowhere')"
                            :options="destinationOptions"
                        />
                    </Field>
                    <Field :label="t('common.color')">
                        <input v-model="categoryForm.color" type="color" class="h-9 w-16 cursor-pointer rounded-md border" />
                    </Field>
                    <div class="flex flex-col justify-end gap-2">
                        <Label class="flex items-center gap-2"><Checkbox v-model="categoryForm.active" /> {{ t('common.active') }}</Label>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted" :style="{ background: categoryForm.color }">
                        <img v-if="categoryPhotoPreview" :src="categoryPhotoPreview" alt="" class="size-full object-cover" />
                        <img v-else-if="editingCategory?.photoUrl && !categoryForm.removePhoto" :src="editingCategory.photoUrl" alt="" class="size-full object-cover" />
                        <ImageOff v-else class="size-8 text-white/80" />
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <Label>{{ t('admin.catalog.categoryPhoto') }}</Label>
                        <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="block w-full text-sm" @change="pickCategoryPhoto" />
                        <p class="text-xs text-muted-foreground">{{ t('admin.catalog.categoryPhotoHelp') }}</p>
                        <Button
                            v-if="editingCategory?.photoUrl && !categoryPhoto"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="categoryForm.removePhoto = !categoryForm.removePhoto"
                        >
                            {{ categoryForm.removePhoto ? t('common.cancel') : t('admin.catalog.removePhoto') }}
                        </Button>
                        <p class="text-xs text-destructive">{{ (categoryForm.errors as Record<string, string>).photo }}</p>
                    </div>
                </div>
                <Field v-if="modifierGroups.length" :label="t('admin.catalog.modifierGroups')">
                    <div class="flex flex-wrap gap-2">
                        <Label v-for="group in modifierGroups" :key="group.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm font-normal">
                            <Checkbox :model-value="categoryForm.modifierGroupIds.includes(group.id)" @update:model-value="toggle(categoryForm.modifierGroupIds, group.id)" />
                            {{ tr(group.name) }}
                        </Label>
                    </div>
                </Field>
                <DialogFooter class="gap-2">
                    <Button
                        v-if="editingCategory"
                        type="button"
                        variant="ghost"
                        class="mr-auto text-destructive"
                        @click="deleting = { kind: 'category', id: editingCategory.id, label: tr(editingCategory.name) }"
                    >
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="categoryForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="productOpen">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
            <form class="space-y-4" @submit.prevent="saveProduct">
                <DialogHeader>
                    <DialogTitle>{{ editingProduct ? t('common.edit') : t('admin.catalog.newProduct') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-6 md:grid-cols-[1fr_18rem]">
                    <div class="space-y-4">
                        <TransInput v-model="productForm.name" :label="t('common.name')" :error="productForm.errors['name.ca'] || productForm.errors.name" />
                        <div class="grid gap-4 sm:grid-cols-3">
                            <Field :label="t('common.price')" :error="productForm.errors.price" :help="t('admin.catalog.priceHelp')">
                                <Input v-model="priceInput" inputmode="decimal" :placeholder="t('admin.catalog.noPrice')" />
                            </Field>
                            <Field :label="t('admin.catalog.vatRate')" :error="productForm.errors.vatRate">
                                <SelectInput v-model="productForm.vatRate" :options="vatOptions" />
                            </Field>
                            <Field :label="t('admin.catalog.category')" :error="productForm.errors.categoryId">
                                <SelectInput v-model="productForm.categoryId" :options="categoryOptions" />
                            </Field>
                            <Field :label="t('admin.catalog.destination')" class="sm:col-span-2">
                                <SelectInput
                                    v-model="productForm.destinationId"
                                    :placeholder="t('admin.printing.routing.likeFamily', { name: inheritedName(familyDestination(productForm.categoryId)) })"
                                    :options="destinationOptions"
                                />
                            </Field>
                            <Field :label="t('common.color')">
                                <input v-model="productForm.color" type="color" class="h-9 w-16 cursor-pointer rounded-md border" />
                            </Field>
                        </div>
                        <div class="flex flex-wrap gap-4">
                            <Label class="flex items-center gap-2"><Checkbox v-model="productForm.active" /> {{ t('common.active') }}</Label>
                            <Label class="flex items-center gap-2"><Checkbox v-model="productForm.soldOut" /> {{ t('admin.catalog.soldOut') }}</Label>
                        </div>
                        <Field :label="t('admin.catalog.allergens')">
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="allergen in allergens"
                                    :key="allergen"
                                    type="button"
                                    class="rounded-full border px-2.5 py-1 text-xs"
                                    :class="productForm.allergens.includes(allergen) ? 'border-amber-500 bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' : ''"
                                    @click="toggle(productForm.allergens, allergen)"
                                >
                                    {{ t(`allergens.${allergen}`) }}
                                </button>
                            </div>
                        </Field>
                        <Field v-if="modifierGroups.length" :label="t('admin.catalog.modifierGroups')">
                            <div class="flex flex-wrap gap-2">
                                <Label v-for="group in modifierGroups" :key="group.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm font-normal">
                                    <Checkbox :model-value="productForm.modifierGroupIds.includes(group.id)" @update:model-value="toggle(productForm.modifierGroupIds, group.id)" />
                                    {{ tr(group.name) }}
                                </Label>
                            </div>
                        </Field>
                    </div>
                    <div class="space-y-2">
                        <Label>{{ t('admin.catalog.photo') }}</Label>
                        <ImageCropper v-if="photoFile" :file="photoFile" @change="crop = $event" />
                        <div v-else class="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-muted">
                            <img v-if="editingProduct?.photoUrl && !productForm.removePhoto" :src="editingProduct.photoUrl" alt="" class="size-full object-cover" />
                            <ImageOff v-else class="size-10 text-muted-foreground" />
                        </div>
                        <input type="file" accept="image/*" class="block w-full text-sm" @change="pickPhoto" />
                        <Button
                            v-if="editingProduct?.photoUrl && !photoFile"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="productForm.removePhoto = !productForm.removePhoto"
                        >
                            {{ productForm.removePhoto ? t('common.cancel') : t('admin.catalog.removePhoto') }}
                        </Button>
                        <p class="text-xs text-destructive">{{ (productForm.errors as Record<string, string>).photo }}</p>
                    </div>
                </div>
                <DialogFooter class="gap-2">
                    <Button
                        v-if="editingProduct"
                        type="button"
                        variant="ghost"
                        class="mr-auto text-destructive"
                        @click="deleting = { kind: 'product', id: editingProduct.id, label: tr(editingProduct.name) }"
                    >
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="productForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="groupOpen">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <form class="space-y-4" @submit.prevent="saveGroup">
                <DialogHeader>
                    <DialogTitle>{{ editingGroup ? t('common.edit') : t('admin.catalog.newModifierGroup') }}</DialogTitle>
                </DialogHeader>
                <TransInput v-model="groupForm.name" :label="t('common.name')" :error="groupForm.errors.name" />
                <div class="flex gap-4">
                    <Label class="flex items-center gap-2"><Checkbox v-model="groupForm.multiple" /> {{ t('admin.catalog.multiple') }}</Label>
                    <Label class="flex items-center gap-2"><Checkbox v-model="groupForm.required" /> {{ t('admin.catalog.required') }}</Label>
                </div>
                <div class="space-y-2">
                    <Label>{{ t('order.modifiers') }}</Label>
                    <p v-if="groupForm.modifiers.length > 1" class="text-xs text-muted-foreground">{{ t('admin.catalog.modifierOrderHelp') }}</p>
                    <VueDraggable v-model="groupForm.modifiers" handle=".modifier-handle" :animation="150" class="space-y-2">
                        <div v-for="(modifier, index) in groupForm.modifiers" :key="modifier.key" class="flex items-end gap-2 rounded-lg border bg-card p-2">
                            <div class="flex flex-col items-center gap-0.5 self-center">
                                <Button type="button" variant="ghost" size="icon" class="size-7" :disabled="index === 0" :title="t('admin.catalog.moveUp')" @click="moveModifier(index, -1)">
                                    <ArrowUp class="size-4" />
                                </Button>
                                <GripVertical class="modifier-handle size-4 cursor-grab text-muted-foreground" />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-7"
                                    :disabled="index === groupForm.modifiers.length - 1"
                                    :title="t('admin.catalog.moveDown')"
                                    @click="moveModifier(index, 1)"
                                >
                                    <ArrowDown class="size-4" />
                                </Button>
                            </div>
                            <TransInput v-model="modifier.name" :label="`${t('admin.catalog.modifier')} ${index + 1}`" class="flex-1" />
                            <Field :label="t('admin.catalog.priceDelta')" class="w-28">
                                <Input v-model="modifier.price" inputmode="decimal" />
                            </Field>
                            <Button type="button" variant="ghost" size="icon" @click="groupForm.modifiers.splice(index, 1)"><Trash2 class="size-4" /></Button>
                        </div>
                    </VueDraggable>
                    <Button type="button" variant="outline" size="sm" @click="addModifier">
                        <Plus class="size-4" /> {{ t('common.add') }}
                    </Button>
                </div>
                <Field :label="`${t('admin.catalog.appliesTo')} · ${t('admin.catalog.categories')}`">
                    <div class="flex flex-wrap gap-2">
                        <Label v-for="category in tree" :key="category.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm font-normal">
                            <Checkbox :model-value="groupForm.categoryIds.includes(category.id)" @update:model-value="toggle(groupForm.categoryIds, category.id)" />
                            {{ tr(category.name) }}
                        </Label>
                    </div>
                </Field>
                <Field :label="`${t('admin.catalog.appliesTo')} · ${t('admin.catalog.products')}`">
                    <Input v-model="productSearch" :placeholder="t('common.search')" class="mb-2" />
                    <div class="flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                        <Label v-for="product in filteredProductsForGroup" :key="product.id" class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm font-normal">
                            <Checkbox :model-value="groupForm.productIds.includes(product.id)" @update:model-value="toggle(groupForm.productIds, product.id)" />
                            {{ tr(product.name) }}
                        </Label>
                    </div>
                </Field>
                <DialogFooter class="gap-2">
                    <Button
                        v-if="editingGroup"
                        type="button"
                        variant="ghost"
                        class="mr-auto text-destructive"
                        @click="deleting = { kind: 'group', id: editingGroup.id, label: tr(editingGroup.name) }"
                    >
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="groupForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('common.delete')"
        :description="deleting?.label"
        :confirm-label="t('common.delete')"
        @confirm="confirmDelete"
    />
</template>
