<script setup lang="ts">
import { Plus, Search, UserRound, UserX } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CustomerForm from '@/components/pos/CustomerForm.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t } from '@/i18n';
import { HttpError } from '@/lib/http';
import { searchCustomers } from '@/pos/customers';
import type { Customer, CustomerListItem } from '@/pos/customers';
import { formatMoney } from '@/pos/money';

/** Choose the customer of an order (search by code or name), create one, or take it off. */
defineProps<{ current: { id: number; code: string; name: string } | null }>();
const emit = defineEmits<{ select: [customer: Pick<Customer, 'id' | 'code' | 'name'> | null]; close: [] }>();

const open = ref(true);
const query = ref('');
const results = ref<CustomerListItem[]>([]);
const loading = ref(false);
const creating = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;

async function search(): Promise<void> {
    loading.value = true;

    try {
        results.value = await searchCustomers(query.value.trim());
    } catch (e) {
        toast.error(e instanceof HttpError ? e.message : t('common.offlineOnly'));
    } finally {
        loading.value = false;
    }
}

watch(query, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(search, 250);
});

watch(open, (value) => !value && emit('close'));
onMounted(search);

function choose(customer: Pick<Customer, 'id' | 'code' | 'name'> | null): void {
    emit('select', customer);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><UserRound class="size-5" /> {{ t('customers.pickTitle') }}</DialogTitle>
                <DialogDescription>{{ t('customers.pickHelp') }}</DialogDescription>
            </DialogHeader>

            <CustomerForm v-if="creating" @saved="choose" @cancel="creating = false" />

            <template v-else>
                <div class="flex gap-2">
                    <div class="relative min-w-0 flex-1">
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="query"
                            type="search"
                            :placeholder="t('customers.search')"
                            class="h-11 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-11 text-base outline-none focus:border-[#00056a] focus:ring-2 focus:ring-[#00056a]/20"
                        />
                    </div>
                    <Button class="h-11 bg-[#00056a]" @click="creating = true"><Plus class="size-4" /> {{ t('customers.new') }}</Button>
                </div>

                <div class="max-h-80 divide-y overflow-y-auto rounded-lg border">
                    <button
                        v-for="customer in results"
                        :key="customer.id"
                        type="button"
                        class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50"
                        :class="current?.id === customer.id ? 'bg-[#00056a]/10' : ''"
                        @click="choose(customer)"
                    >
                        <span class="shrink-0 rounded bg-[#00056a] px-2 py-0.5 text-sm font-bold text-white">{{ customer.code }}</span>
                        <span class="min-w-0 flex-1 truncate font-medium">{{ customer.name }}</span>
                        <span v-if="customer.pendingCount" class="shrink-0 text-xs text-slate-500">{{ t('customers.pendingShort', { count: customer.pendingCount, total: formatMoney(customer.pendingTotal) }) }}</span>
                    </button>
                    <p v-if="!results.length" class="p-4 text-center text-sm text-slate-500">{{ loading ? t('common.loading') : t('customers.none') }}</p>
                </div>

                <Button v-if="current" variant="outline" class="h-11 text-red-700" @click="choose(null)"><UserX class="size-4" /> {{ t('customers.remove', { code: current.code }) }}</Button>
            </template>
        </DialogContent>
    </Dialog>
</template>
