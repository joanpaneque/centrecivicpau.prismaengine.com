<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Copy, Link2, Pencil, Plus, Printer, Sparkles, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import TransInput from '@/components/admin/TransInput.vue';
import MenuPrintDialog from '@/components/menus/MenuPrintDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import { centsToInput, money, parseMoney, toTrans } from '@/lib/format';
import { cartas as cartasRoute } from '@/routes/admin';
import { destroy, duplicate, store, update } from '@/routes/admin/cartas';

/**
 * Printable cartas (drinks, tapas, desserts…), apart from the set menus. A dish linked to a
 * product of the POS without a price of its own shows the product's price.
 */
type Trans = Record<string, string>;
type Item = {
    id: number;
    productId: number | null;
    name: Trans | null;
    description: Trans | null;
    price: number | null;
    priceText: string | null;
    effectivePrice: number | null;
    displayName: Trans;
};
type Section = { id: number; name: Trans; items: Item[] };
type Carta = { id: number; name: Trans; subtitle: Trans | null; notes: Trans | null; maxPages: number | null; css: string | null; template: string; active: boolean; sections: Section[] };

const props = defineProps<{
    cartas: Carta[];
    products: { id: number; name: Trans; price: number | null }[];
}>();

const { t, tr } = useI18n();

type FormItem = { id: number | null; productId: number | null; name: { ca: string; es: string }; description: { ca: string; es: string }; price: string; priceText: string };
type FormSection = { id: number | null; name: { ca: string; es: string }; items: FormItem[] };

const open = ref(false);
const editing = ref<Carta | null>(null);
const printing = ref<Carta | null>(null);
const form = useForm({
    name: { ca: '', es: '' },
    subtitle: { ca: '', es: '' },
    notes: { ca: '', es: '' },
    maxPages: null as number | null,
    css: '',
    active: true,
    sections: [] as FormSection[],
});

const pageOptions = computed(() => [
    { value: null as number | null, label: t('admin.cartas.pagesAuto') },
    ...[1, 2, 3, 4].map((n) => ({ value: n as number | null, label: t('admin.cartas.pagesMax', { count: n }) })),
]);

const productOptions = computed(() => props.products.map((p) => ({ value: p.id as number | null, label: `${tr(p.name)}${p.price !== null ? ` · ${money(p.price)}` : ''}` })));
const productsById = computed(() => new Map(props.products.map((p) => [p.id, p])));

function emptyItem(): FormItem {
    return { id: null, productId: null, name: { ca: '', es: '' }, description: { ca: '', es: '' }, price: '', priceText: '' };
}

function openCarta(carta: Carta | null): void {
    editing.value = carta;
    form.clearErrors();
    form.name = toTrans(carta?.name);
    form.subtitle = toTrans(carta?.subtitle);
    form.notes = toTrans(carta?.notes);
    form.maxPages = carta?.maxPages ?? null;
    form.css = carta?.css ?? '';
    form.active = carta?.active ?? true;
    form.sections = carta
        ? carta.sections.map((s) => ({
              id: s.id,
              name: toTrans(s.name),
              items: s.items.map((i) => ({
                  id: i.id,
                  productId: i.productId,
                  name: toTrans(i.name),
                  description: toTrans(i.description),
                  price: centsToInput(i.price),
                  priceText: i.priceText ?? '',
              })),
          }))
        : [{ id: null, name: { ca: '', es: '' }, items: [emptyItem()] }];
    open.value = true;
}

function moveSection(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= form.sections.length) {
        return;
    }

    const [section] = form.sections.splice(index, 1);
    form.sections.splice(target, 0, section);
}

function productPlaceholder(item: FormItem, field: 'name' | 'price', lang: 'ca' | 'es' = 'ca'): string {
    const product = item.productId ? productsById.value.get(item.productId) : null;

    if (!product) {
        return field === 'price' ? t('common.price') : lang.toUpperCase();
    }

    return field === 'price' ? (product.price !== null ? centsToInput(product.price) : t('common.price')) : (product.name[lang] ?? '');
}

function save(): void {
    form.transform((data) => ({
        ...data,
        subtitle: data.subtitle.ca || data.subtitle.es ? data.subtitle : null,
        notes: data.notes.ca || data.notes.es ? data.notes : null,
        layout: { maxPages: data.maxPages, css: data.css.trim() || null },
        sections: data.sections.map((s) => ({
            id: s.id,
            name: s.name,
            items: s.items.map((i) => ({
                id: i.id,
                productId: i.productId,
                name: i.name.ca || i.name.es ? i.name : null,
                description: i.description.ca || i.description.es ? i.description : null,
                price: i.price.trim() === '' ? null : parseMoney(i.price),
                priceText: i.priceText.trim() || null,
            })),
        })),
    }));

    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (editing.value) {
        form.patch(update.url(editing.value.id), options);
    } else {
        form.post(store.url(), options);
    }
}

const layoutError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return errors['layout.css'] ?? errors['layout.maxPages'];
});

function itemError(section: number, item: number): string | undefined {
    const errors = form.errors as Record<string, string>;

    return errors[`sections.${section}.items.${item}.price`] ?? errors[`sections.${section}.items.${item}.name`];
}

const deleting = ref<Carta | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function remove(): void {
    if (deleting.value) {
        router.delete(destroy.url(deleting.value.id), {
            preserveScroll: true,
            onFinish: () => {
                deleting.value = null;
                open.value = false;
            },
        });
    }
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Cartes', href: cartasRoute() }] } });
</script>

<template>
    <Head :title="t('admin.cartas.title')" />
    <PageHeader :title="t('admin.cartas.title')" :description="t('admin.cartas.description')">
        <Button variant="outline" as-child>
            <Link href="/gestion/asistente"><Sparkles class="size-4" /> {{ t('admin.cartas.withAssistant') }}</Link>
        </Button>
        <Button @click="openCarta(null)"><Plus class="size-4" /> {{ t('admin.cartas.new') }}</Button>
    </PageHeader>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="carta in cartas" :key="carta.id" class="flex flex-col rounded-xl border bg-card p-4" :class="{ 'opacity-60': !carta.active }">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <div class="font-semibold">{{ tr(carta.name) }}</div>
                    <div v-if="carta.subtitle" class="text-xs text-muted-foreground">{{ tr(carta.subtitle) }}</div>
                </div>
                <Badge variant="secondary">{{ t(`menuPrint.templates.${carta.template}`) }}</Badge>
            </div>
            <div class="mt-3 flex-1 space-y-2 text-sm">
                <div v-for="section in carta.sections" :key="section.id">
                    <div class="text-xs font-medium text-muted-foreground uppercase">{{ tr(section.name) }}</div>
                    <div v-for="item in section.items" :key="item.id" class="flex items-baseline gap-2">
                        <span class="min-w-0 flex-1 truncate">{{ tr(item.displayName) }}</span>
                        <Link2 v-if="item.price === null && item.productId" class="size-3 shrink-0 text-muted-foreground" :title="t('studio.priceFromCatalog')" />
                        <span class="tabular-nums">{{ item.priceText ?? (item.effectivePrice !== null ? money(item.effectivePrice) : '—') }}</span>
                    </div>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <Button size="sm" @click="printing = carta"><Printer class="size-4" /> {{ t('studio.printMenu') }}</Button>
                <Button size="sm" variant="outline" @click="openCarta(carta)"><Pencil class="size-4" /> {{ t('common.edit') }}</Button>
                <Button size="sm" variant="ghost" @click="router.post(duplicate.url(carta.id), {}, { preserveScroll: true })">
                    <Copy class="size-4" /> {{ t('common.duplicate') }}
                </Button>
            </div>
        </div>
    </div>
    <p v-if="cartas.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('admin.cartas.empty') }}</p>

    <MenuPrintDialog
        v-if="printing"
        kind="carta"
        :menu-id="printing.id"
        :menu-name="tr(printing.name)"
        :template="printing.template"
        @template="(value) => printing && (printing.template = value)"
        @close="printing = null"
    />

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[94vh] overflow-y-auto sm:max-w-4xl">
            <form class="space-y-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{ editing ? t('common.edit') : t('admin.cartas.new') }}</DialogTitle>
                </DialogHeader>

                <TransInput v-model="form.name" :label="t('common.name')" :error="form.errors['name.ca'] || form.errors.name" />
                <TransInput v-model="form.subtitle" :label="t('admin.cartas.subtitle')" />
                <TransInput v-model="form.notes" multiline :label="t('admin.cartas.notes')" :error="form.errors['notes.ca'] || form.errors['notes.es']" />
                <div class="flex flex-wrap items-center gap-4">
                    <Label class="flex items-center gap-2"><Checkbox v-model="form.active" /> {{ t('common.active') }}</Label>
                    <div class="flex items-center gap-2">
                        <Label>{{ t('admin.cartas.pages') }}</Label>
                        <SelectInput v-model="form.maxPages" class="w-48" :options="pageOptions" />
                    </div>
                </div>
                <details class="rounded-lg border p-3 text-sm">
                    <summary class="cursor-pointer font-medium">{{ t('admin.cartas.css') }}</summary>
                    <p class="mt-2 text-xs text-muted-foreground">{{ t('admin.cartas.cssHelp') }}</p>
                    <textarea
                        v-model="form.css"
                        rows="6"
                        spellcheck="false"
                        class="mt-2 w-full rounded-md border border-input bg-transparent p-2 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder=".dish { font-weight: bold; }"
                    />
                    <p v-if="layoutError" class="text-xs text-destructive">{{ layoutError }}</p>
                </details>

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium">{{ t('admin.setMenus.sections') }}</h3>
                        <Button type="button" size="sm" variant="outline" @click="form.sections.push({ id: null, name: { ca: '', es: '' }, items: [emptyItem()] })">
                            <Plus class="size-4" /> {{ t('admin.setMenus.addSection') }}
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('admin.cartas.priceHelp') }}</p>

                    <div v-for="(section, sIndex) in form.sections" :key="sIndex" class="space-y-3 rounded-lg border p-3">
                        <div class="flex items-end gap-2">
                            <TransInput v-model="section.name" :label="t('admin.setMenus.sectionName')" class="flex-1" />
                            <div class="flex">
                                <Button type="button" variant="ghost" size="icon" @click="moveSection(sIndex, -1)"><ArrowUp class="size-4" /></Button>
                                <Button type="button" variant="ghost" size="icon" @click="moveSection(sIndex, 1)"><ArrowDown class="size-4" /></Button>
                                <Button type="button" variant="ghost" size="icon" class="text-destructive" @click="form.sections.splice(sIndex, 1)">
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div v-for="(item, iIndex) in section.items" :key="iIndex" class="grid gap-2 rounded-md bg-muted/40 p-2">
                            <div class="grid items-end gap-2 sm:grid-cols-[13rem_1fr_1fr_6rem_5rem_auto]">
                                <SelectInput v-model="item.productId" :placeholder="t('admin.setMenus.freeText')" :options="productOptions" />
                                <Input v-model="item.name.ca" :placeholder="productPlaceholder(item, 'name', 'ca') || 'CA'" />
                                <Input v-model="item.name.es" :placeholder="productPlaceholder(item, 'name', 'es') || 'ES'" />
                                <Input v-model="item.price" inputmode="decimal" :disabled="!!item.priceText.trim()" :placeholder="productPlaceholder(item, 'price')" />
                                <Input v-model="item.priceText" maxlength="20" :title="t('admin.cartas.priceTextHelp')" :placeholder="t('admin.cartas.priceText')" />
                                <Button type="button" variant="ghost" size="icon" @click="section.items.splice(iIndex, 1)"><Trash2 class="size-4" /></Button>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <Input v-model="item.description.ca" class="h-8 text-xs" :placeholder="`${t('admin.cartas.descriptionField')} (CA)`" />
                                <Input v-model="item.description.es" class="h-8 text-xs" :placeholder="`${t('admin.cartas.descriptionField')} (ES)`" />
                            </div>
                            <p v-if="itemError(sIndex, iIndex)" class="text-xs text-destructive">{{ itemError(sIndex, iIndex) }}</p>
                        </div>

                        <Button type="button" size="sm" variant="ghost" @click="section.items.push(emptyItem())">
                            <Plus class="size-4" /> {{ t('admin.setMenus.addItem') }}
                        </Button>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button v-if="editing" type="button" variant="ghost" class="mr-auto text-destructive" @click="deleting = editing">
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('common.delete')"
        :description="deleting ? tr(deleting.name) : ''"
        :confirm-label="t('common.delete')"
        @confirm="remove"
    />
</template>
