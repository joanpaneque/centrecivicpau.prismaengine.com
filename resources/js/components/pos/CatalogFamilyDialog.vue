<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tr } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { categoryDestination, familyPath, findDuplicateFamily } from '@/pos/catalog';
import { sorted, state, upsert } from '@/pos/store';
import type { Category } from '@/pos/types';

/**
 * New family (or subfamily) from the TPV. It refuses a name that already exists.
 */
const props = defineProps<{ parentId: number | null }>();
const emit = defineEmits<{ close: []; created: [category: Category] }>();

const COLORS = ['#00056a', '#2563eb', '#0891b2', '#059669', '#65a30d', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#7c3aed', '#475569'];

const nameCa = ref('');
const nameEs = ref('');
const parentId = ref<number | null>(props.parentId);
const color = ref<string | null>(null);
const destinationId = ref<number | null>(null);
const saving = ref(false);

const roots = computed(() => sorted.categories.value.filter((c) => !c.parentId));
const destinations = computed(() => Object.values(state.destinations));
const inherited = computed(() => (parentId.value ? categoryDestination(parentId.value) : null));
const duplicate = computed(() => findDuplicateFamily([nameCa.value, nameEs.value]));
const canSave = computed(() => (nameCa.value.trim() !== '' || nameEs.value.trim() !== '') && !duplicate.value && !saving.value);

async function save(): Promise<void> {
    if (!canSave.value) {
        return;
    }

    saving.value = true;

    try {
        const ca = nameCa.value.trim() || nameEs.value.trim();
        const response = await api<{ category: Category }>('POST', '/tpv/api/categories', {
            name: { ca, es: nameEs.value.trim() || ca },
            parentId: parentId.value,
            color: color.value,
            destinationId: destinationId.value,
        });
        upsert('categories', response.category);
        toast.success(t('order.catalogCreate.familyCreated', { name: tr(response.category.name) }));
        emit('created', response.category);
        emit('close');
    } catch (error) {
        const existing = error instanceof HttpError && error.status === 422 ? (error.body as { duplicate?: Category }).duplicate : undefined;

        if (existing) {
            upsert('categories', existing);
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
                <DialogTitle>{{ parentId ? t('order.catalogCreate.newSubfamily') : t('order.catalogCreate.newFamily') }}</DialogTitle>
                <DialogDescription class="sr-only">{{ t('order.catalogCreate.newFamily') }}</DialogDescription>
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
                    <AlertTriangle class="size-5 shrink-0" /> {{ t('order.catalogCreate.familyDuplicate', { name: familyPath(duplicate.id) }) }}
                </p>

                <div>
                    <p class="mb-2 text-sm font-medium">{{ t('order.catalogCreate.inside') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="h-11 rounded-xl px-3 text-sm font-semibold"
                            :class="parentId === null ? 'bg-[#00056a] text-white' : 'bg-slate-100 text-slate-700'"
                            @click="parentId = null"
                        >
                            {{ t('order.catalogCreate.mainFamily') }}
                        </button>
                        <button
                            v-for="root in roots"
                            :key="root.id"
                            type="button"
                            class="h-11 rounded-xl px-3 text-sm font-semibold"
                            :class="parentId === root.id ? 'bg-[#00056a] text-white' : 'bg-slate-100 text-slate-700'"
                            @click="parentId = root.id"
                        >
                            {{ tr(root.name) }}
                        </button>
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium">{{ t('common.color') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="size-11 rounded-xl border-2 bg-white text-xs text-slate-500"
                            :class="color === null ? 'border-[#00056a]' : 'border-slate-200'"
                            @click="color = null"
                        >
                            —
                        </button>
                        <button
                            v-for="c in COLORS"
                            :key="c"
                            type="button"
                            class="size-11 rounded-xl ring-offset-2"
                            :class="color === c ? 'ring-4 ring-slate-800' : ''"
                            :style="{ background: c }"
                            @click="color = c"
                        />
                    </div>
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
                            {{
                                parentId
                                    ? t('order.catalogCreate.likeParent', { name: inherited ? tr(state.destinations[inherited]?.name) : t('order.noDestination') })
                                    : t('order.noDestination')
                            }}
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
