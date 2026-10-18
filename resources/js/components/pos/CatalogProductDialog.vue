<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import MoneyKeypad from '@/components/pos/MoneyKeypad.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tr } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { categoryDestination, familyPath, findDuplicateProduct } from '@/pos/catalog';
import { formatMoney, parseMoney } from '@/pos/money';
import { sorted, state, upsert } from '@/pos/store';
import type { Product } from '@/pos/types';

/**
 * New catalog product from the TPV, in the family that is open. It refuses a name that
 * already exists (and says where that product is).
 */
const props = defineProps<{ categoryId: number | null }>();
const emit = defineEmits<{ close: []; created: [product: Product] }>();

const nameCa = ref('');
const nameEs = ref('');
const priceRaw = ref('');
const categoryId = ref<number | null>(props.categoryId);
const destinationId = ref<number | null>(null);
const saving = ref(false);

const price = computed<number | null>(() => (priceRaw.value.trim() === '' ? null : parseMoney(priceRaw.value)));
const families = computed(() => {
    const roots = sorted.categories.value.filter((c) => !c.parentId);

    return roots.flatMap((root) => [root, ...sorted.categories.value.filter((c) => c.parentId === root.id)]);
});
const destinations = computed(() => Object.values(state.destinations));
const inherited = computed(() => categoryDestination(categoryId.value));
const duplicate = computed(() => findDuplicateProduct([nameCa.value, nameEs.value]));
const canSave = computed(() => (nameCa.value.trim() !== '' || nameEs.value.trim() !== '') && !!categoryId.value && !duplicate.value && !saving.value);

async function save(): Promise<void> {
    if (!canSave.value) {
        return;
    }

    saving.value = true;

    try {
        const ca = nameCa.value.trim() || nameEs.value.trim();
        const response = await api<{ product: Product }>('POST', '/tpv/api/products', {
            name: { ca, es: nameEs.value.trim() || ca },
            price: price.value,
            categoryId: categoryId.value,
            destinationId: destinationId.value,
        });
        upsert('products', response.product);
        toast.success(t('order.catalogCreate.productCreated', { name: tr(response.product.name) }));
        emit('created', response.product);
        emit('close');
    } catch (error) {
        const existing = error instanceof HttpError && error.status === 422 ? (error.body as { duplicate?: Product }).duplicate : undefined;

        if (existing) {
            upsert('products', existing);
        }

        toast.error(error instanceof HttpError && error.status === 422 ? error.message : t('common.offlineOnly'));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="true" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-h-[94svh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ t('order.catalogCreate.newProduct') }}</DialogTitle>
                <DialogDescription class="sr-only">{{ t('order.catalogCreate.newProduct') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ t('order.catalogCreate.nameCa') }}</label>
                        <Input v-model="nameCa" class="h-12 text-lg" autocomplete="off" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ t('order.catalogCreate.nameEs') }}</label>
                        <Input v-model="nameEs" class="h-12 text-lg" :placeholder="nameCa" autocomplete="off" />
                    </div>
                </div>
                <p v-if="duplicate" class="flex items-center gap-2 rounded-xl bg-amber-100 p-3 text-sm font-medium text-amber-950">
                    <AlertTriangle class="size-5 shrink-0" />
                    {{ t('order.catalogCreate.productDuplicate', { name: tr(duplicate.name), family: familyPath(duplicate.categoryId) || '—' }) }}
                </p>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('order.catalogCreate.family') }}</label>
                    <select v-model="categoryId" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-base">
                        <option v-for="family in families" :key="family.id" :value="family.id">{{ family.parentId ? `↳ ${familyPath(family.id)}` : tr(family.name) }}</option>
                    </select>
                </div>

                <div>
                    <p class="mb-1 flex justify-between text-sm font-medium">
                        {{ t('common.price') }}
                        <span v-if="price === null" class="font-normal text-slate-500">{{ t('order.inventNoPrice') }}</span>
                        <span v-else class="font-bold">{{ formatMoney(price) }}</span>
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
                            {{ t('order.catalogCreate.likeFamily', { name: inherited ? tr(state.destinations[inherited]?.name) : t('order.noDestination') }) }}
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
            </div>

            <DialogFooter class="gap-2">
                <Button variant="outline" class="h-12" @click="emit('close')">{{ t('common.cancel') }}</Button>
                <Button class="h-12 flex-1 bg-[#00056a]" :disabled="!canSave" @click="save">{{ saving ? t('common.saving') : t('order.catalogCreate.create') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
