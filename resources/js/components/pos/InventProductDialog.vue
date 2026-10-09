<script setup lang="ts">
import { computed, ref } from 'vue';
import MoneyKeypad from '@/components/pos/MoneyKeypad.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t, tr } from '@/i18n';
import { parseMoney } from '@/pos/money';
import type { InventedProduct } from '@/pos/orders';
import { state } from '@/pos/store';

const emit = defineEmits<{ close: []; add: [product: InventedProduct] }>();

const name = ref('');
const priceRaw = ref('');
const destinations = computed(() => Object.values(state.destinations));
const destinationId = ref<number | null>(destinations.value.find((d) => d.code === 'kitchen')?.id ?? destinations.value[0]?.id ?? null);
const priceCents = computed(() => parseMoney(priceRaw.value));
const canAdd = computed(() => name.value.trim() !== '' && priceCents.value >= 0);

function add(): void {
    if (!canAdd.value) {
        return;
    }

    emit('add', { name: name.value.trim(), unitPrice: priceCents.value, destinationId: destinationId.value });
    emit('close');
}
</script>

<template>
    <Dialog :open="true" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-h-[94svh] max-w-md overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ t('order.invent') }}</DialogTitle>
            </DialogHeader>
            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ t('common.name') }}</label>
                    <Input v-model="name" class="h-12 text-lg" :placeholder="t('order.inventName')" autocomplete="off" />
                </div>
                <div>
                    <p class="mb-1 text-sm font-medium">{{ t('common.price') }}</p>
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
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" class="h-12" @click="emit('close')">{{ t('common.cancel') }}</Button>
                <Button class="h-12 bg-[#00056a]" :disabled="!canAdd" @click="add">{{ t('order.addToOrder') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
