<script setup lang="ts">
import { AlertTriangle, ArrowLeft, Loader2, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import MoneyKeypad from '@/components/pos/MoneyKeypad.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tr } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { categoryColor, categoryDestination, familyPath, findDuplicateProduct, NEUTRAL_TILE, normalize, readableText } from '@/pos/catalog';
import { formatMoney, parseMoney } from '@/pos/money';
import type { InventedProduct } from '@/pos/orders';
import { sorted, state, upsert } from '@/pos/store';
import { sync } from '@/pos/sync';
import type { Category, Product, T9n } from '@/pos/types';

/**
 * Invented product in three short steps: type it, accept the AI's clean name (ca + es) and,
 * when "add to the catalog" is ticked, pick the family the AI proposes (or another one).
 * Without connection the AI is skipped and the product stays a one-off line.
 */
const emit = defineEmits<{ close: []; add: [product: InventedProduct]; created: [product: Product] }>();

type Suggestion = { ca: string; es: string; categoryId: number | null };

const step = ref<'form' | 'checking' | 'suggestion' | 'family' | 'saving' | 'duplicate'>('form');
/** A product with this name already exists: offer it instead of a duplicate. */
const duplicate = ref<Product | null>(null);
const name = ref('');
const priceRaw = ref('');
const addToCatalog = ref(false);
const destinations = computed(() => Object.values(state.destinations));
const destinationId = ref<number | null>(destinations.value.find((d) => d.code === 'kitchen')?.id ?? destinations.value[0]?.id ?? null);
const suggestion = ref<Suggestion | null>(null);
const finalName = ref<T9n>({ ca: '', es: '' });
const familyId = ref<number | null>(null);

const price = computed<number | null>(() => (priceRaw.value.trim() === '' ? null : parseMoney(priceRaw.value)));
const canAdd = computed(() => name.value.trim() !== '');
const families = computed(() => {
    const roots = sorted.categories.value.filter((c) => !c.parentId);

    return roots.flatMap((root) => [{ category: root, child: false }, ...sorted.categories.value.filter((c) => c.parentId === root.id).map((category) => ({ category, child: true }))]);
});

function color(category: Category): string {
    return categoryColor(category) ?? NEUTRAL_TILE;
}

async function next(): Promise<void> {
    if (!canAdd.value) {
        return;
    }

    const typed = name.value.trim();
    finalName.value = { ca: typed, es: typed };
    suggestion.value = null;

    if (showDuplicate([typed])) {
        return;
    }

    if (sync.online) {
        step.value = 'checking';

        try {
            const response = await api<{ suggestion: Suggestion | null }>('POST', '/tpv/api/products/suggest', { name: typed }, 12000);
            suggestion.value = response.suggestion;
        } catch {
            suggestion.value = null;
        }
    }

    const s = suggestion.value;
    const differs = !!s && (normalize(s.ca) !== normalize(typed) || normalize(s.es) !== normalize(typed));

    if (s && differs) {
        step.value = 'suggestion';

        return;
    }

    if (s) {
        finalName.value = { ca: s.ca, es: s.es };
    }

    afterName();
}

function useSuggestion(accept: boolean): void {
    if (accept && suggestion.value) {
        finalName.value = { ca: suggestion.value.ca, es: suggestion.value.es };
    }

    afterName();
}

function showDuplicate(names: string[]): boolean {
    duplicate.value = findDuplicateProduct(names);

    if (duplicate.value) {
        step.value = 'duplicate';
    }

    return !!duplicate.value;
}

function useExisting(): void {
    if (duplicate.value) {
        emit('created', duplicate.value);
        emit('close');
    }
}

function afterName(): void {
    // The AI may have turned "croq. jamon" into the name of a product that already exists.
    if (showDuplicate([finalName.value.ca, finalName.value.es])) {
        return;
    }

    if (!addToCatalog.value) {
        finish();

        return;
    }

    familyId.value = suggestion.value?.categoryId ?? null;
    step.value = 'family';
}

function finish(): void {
    emit('add', { name: finalName.value, unitPrice: price.value, destinationId: destinationId.value });
    emit('close');
}

async function createProduct(): Promise<void> {
    if (!familyId.value) {
        return;
    }

    step.value = 'saving';
    const inherited = categoryDestination(familyId.value);

    try {
        const response = await api<{ product: Product }>('POST', '/tpv/api/products', {
            name: finalName.value,
            price: price.value,
            categoryId: familyId.value,
            destinationId: destinationId.value !== inherited ? destinationId.value : null,
        });
        upsert('products', response.product);
        toast.success(t('order.productCreated'));
        emit('created', response.product);
        emit('close');
    } catch (error) {
        const existing = error instanceof HttpError && error.status === 422 ? (error.body as { duplicate?: Product }).duplicate : undefined;

        if (existing) {
            upsert('products', existing);
            duplicate.value = existing;
            step.value = 'duplicate';

            return;
        }

        toast.error(t('order.productCreateOffline'));
        finish();
    }
}
</script>

<template>
    <Dialog :open="true" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-h-[94svh] max-w-lg overflow-y-auto">
            <template v-if="step === 'form'">
                <DialogHeader>
                    <DialogTitle>{{ t('order.invent') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ t('order.inventName') }}</DialogDescription>
                </DialogHeader>
                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ t('common.name') }}</label>
                        <Input v-model="name" class="h-12 text-lg" :placeholder="t('order.inventName')" autocomplete="off" />
                    </div>
                    <div>
                        <p class="mb-1 flex justify-between text-sm font-medium">
                            {{ t('common.price') }}
                            <span v-if="price === null" class="font-normal text-slate-500">{{ t('order.inventNoPrice') }}</span>
                        </p>
                        <MoneyKeypad v-model="priceRaw" embedded :min-cents="0" />
                    </div>
                    <div>
                        <p class="mb-2 text-sm font-medium">{{ t('order.destination') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="h-11 rounded-xl px-3 text-sm font-semibold"
                                :class="destinationId === null ? 'bg-[#00056a] text-white' : 'bg-slate-100 text-slate-700'"
                                @click="destinationId = null"
                            >
                                {{ t('order.noDestination') }}
                            </button>
                            <button
                                v-for="destination in destinations"
                                :key="destination.id"
                                type="button"
                                class="h-11 rounded-xl px-3 text-sm font-semibold"
                                :class="destinationId === destination.id ? 'bg-[#00056a] text-white' : 'bg-slate-100 text-slate-700'"
                                @click="destinationId = destination.id"
                            >
                                {{ tr(destination.name) }}
                            </button>
                        </div>
                    </div>
                    <label class="flex items-start gap-3 rounded-xl bg-slate-50 p-3">
                        <Checkbox v-model="addToCatalog" class="mt-0.5 size-5" />
                        <span>
                            <span class="block text-sm font-semibold">{{ t('order.inventAddToCatalog') }}</span>
                            <span class="block text-xs text-slate-500">{{ t('order.inventAddToCatalogHelp') }}</span>
                        </span>
                    </label>
                </div>
                <DialogFooter class="gap-2">
                    <Button variant="outline" class="h-12" @click="emit('close')">{{ t('common.cancel') }}</Button>
                    <Button class="h-12 flex-1 bg-[#00056a]" :disabled="!canAdd" @click="next">
                        {{ t('order.addToOrder') }}<template v-if="price !== null"> · {{ formatMoney(price) }}</template>
                    </Button>
                </DialogFooter>
            </template>

            <template v-else-if="step === 'checking' || step === 'saving'">
                <DialogHeader>
                    <DialogTitle>{{ t('order.invent') }}</DialogTitle>
                    <DialogDescription class="sr-only">{{ name }}</DialogDescription>
                </DialogHeader>
                <div class="flex flex-col items-center gap-3 py-10 text-slate-600">
                    <Loader2 class="size-10 animate-spin text-[#00056a]" />
                    <p>{{ step === 'checking' ? t('order.aiChecking') : t('common.saving') }}</p>
                </div>
            </template>

            <template v-else-if="step === 'suggestion' && suggestion">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Sparkles class="size-5 text-amber-500" /> {{ t('order.aiSuggestTitle') }}</DialogTitle>
                    <DialogDescription>{{ t('order.aiSuggestTyped', { name: name.trim() }) }}</DialogDescription>
                </DialogHeader>
                <div class="space-y-2 rounded-2xl border-2 border-[#00056a]/20 bg-[#00056a]/5 p-4">
                    <p class="text-2xl font-bold text-[#00056a]">{{ suggestion.ca }}</p>
                    <p class="text-lg text-slate-600">{{ suggestion.es }}</p>
                </div>
                <DialogFooter class="flex-col gap-2 sm:flex-col">
                    <Button class="h-14 w-full bg-[#00056a] text-base" @click="useSuggestion(true)">{{ t('order.aiUseSuggestion') }}</Button>
                    <Button variant="outline" class="h-12 w-full" @click="useSuggestion(false)">{{ t('order.aiKeepTyped', { name: name.trim() }) }}</Button>
                </DialogFooter>
            </template>

            <template v-else-if="step === 'duplicate' && duplicate">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><AlertTriangle class="size-5 text-amber-500" /> {{ t('order.duplicateTitle') }}</DialogTitle>
                    <DialogDescription>{{ t('order.duplicateHelp') }}</DialogDescription>
                </DialogHeader>
                <div class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-4 text-amber-950">
                    <p class="text-2xl font-bold">{{ tr(duplicate.name) }}</p>
                    <p class="text-sm">
                        {{ familyPath(duplicate.categoryId) || '—' }} · {{ duplicate.price !== null ? formatMoney(duplicate.price) : t('order.unpriced') }}
                        <template v-if="!duplicate.active"> · {{ t('order.duplicateInactive') }}</template>
                    </p>
                </div>
                <DialogFooter class="flex-col gap-2 sm:flex-col">
                    <Button v-if="duplicate.active" class="h-14 w-full bg-[#00056a] text-base" @click="useExisting">{{ t('order.duplicateUse', { name: tr(duplicate.name) }) }}</Button>
                    <Button variant="outline" class="h-12 w-full" @click="step = 'form'">{{ t('order.duplicateRename') }}</Button>
                    <Button v-if="!addToCatalog" variant="ghost" class="h-11 w-full text-slate-600" @click="finish">{{ t('order.duplicateOneOff') }}</Button>
                </DialogFooter>
            </template>

            <template v-else-if="step === 'family'">
                <DialogHeader>
                    <DialogTitle>{{ t('order.chooseFamily') }}</DialogTitle>
                    <DialogDescription>{{ tr(finalName) }}<template v-if="price !== null"> · {{ formatMoney(price) }}</template></DialogDescription>
                </DialogHeader>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <button
                        v-for="{ category, child } in families"
                        :key="category.id"
                        type="button"
                        class="relative flex min-h-16 items-center gap-2 rounded-xl p-2 text-left text-sm font-bold transition active:scale-95"
                        :class="familyId === category.id ? 'ring-4 ring-[#00056a] ring-offset-2' : ''"
                        :style="{ background: color(category), color: readableText(color(category)) }"
                        @click="familyId = category.id"
                    >
                        <img v-if="category.photoUrl" :src="category.photoUrl" alt="" class="size-10 shrink-0 rounded-lg object-cover" />
                        <span class="line-clamp-2">
                            <span v-if="child" class="opacity-70">↳ </span>{{ tr(category.name) }}
                        </span>
                        <span v-if="suggestion?.categoryId === category.id" class="absolute -top-2 right-1 flex items-center gap-0.5 rounded-full bg-amber-400 px-1.5 text-[10px] font-bold text-amber-950">
                            <Sparkles class="size-3" /> {{ t('order.aiProposal') }}
                        </span>
                    </button>
                </div>
                <DialogFooter class="gap-2">
                    <Button variant="outline" class="h-12" @click="step = 'form'"><ArrowLeft class="size-4" /></Button>
                    <Button class="h-12 flex-1 bg-[#00056a]" :disabled="!familyId" @click="createProduct">{{ t('order.addToCatalogAndOrder') }}</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
