<script setup lang="ts">
import { reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';
import { HttpError } from '@/lib/http';
import { createCustomer, updateCustomer } from '@/pos/customers';
import type { Customer } from '@/pos/customers';

/** New customer or its data: code and name required, the fiscal data optional. */
const props = defineProps<{ customer?: Customer | null }>();
const emit = defineEmits<{ saved: [customer: Customer]; cancel: [] }>();

const form = reactive({
    code: props.customer?.code ?? '',
    name: props.customer?.name ?? '',
    taxId: props.customer?.taxId ?? '',
    address: props.customer?.address ?? '',
    email: props.customer?.email ?? '',
    phone: props.customer?.phone ?? '',
});
const saving = ref(false);
const error = ref('');

async function save(): Promise<void> {
    if (!form.code.trim() || !form.name.trim() || saving.value) {
        return;
    }

    saving.value = true;
    error.value = '';
    const data = {
        code: form.code.trim(),
        name: form.name.trim(),
        taxId: form.taxId.trim() || null,
        address: form.address.trim() || null,
        email: form.email.trim() || null,
        phone: form.phone.trim() || null,
    };

    try {
        emit('saved', props.customer ? await updateCustomer(props.customer.id, data) : await createCustomer(data));
    } catch (e) {
        error.value = e instanceof HttpError ? e.message : t('common.offlineOnly');
        toast.error(error.value);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <form class="grid gap-2" @submit.prevent="save">
        <div class="grid grid-cols-[9rem_1fr] gap-2">
            <Input v-model="form.code" required maxlength="30" :placeholder="t('customers.code')" class="h-11 font-bold" />
            <Input v-model="form.name" required maxlength="160" :placeholder="t('customers.name')" class="h-11" />
        </div>
        <p class="text-xs text-slate-500">{{ t('customers.optionalHelp') }}</p>
        <div class="grid grid-cols-2 gap-2">
            <Input v-model="form.taxId" maxlength="30" :placeholder="t('customers.taxId')" class="h-11" />
            <Input v-model="form.phone" maxlength="30" inputmode="tel" :placeholder="t('customers.phone')" class="h-11" />
        </div>
        <Input v-model="form.address" maxlength="200" :placeholder="t('customers.address')" class="h-11" />
        <Input v-model="form.email" type="email" maxlength="160" :placeholder="t('customers.email')" class="h-11" />
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <div class="flex justify-end gap-2">
            <Button type="button" variant="outline" class="h-11" @click="emit('cancel')">{{ t('common.cancel') }}</Button>
            <Button type="submit" class="h-11 bg-[#00056a]" :disabled="saving || !form.code.trim() || !form.name.trim()">{{ saving ? t('common.saving') : t('common.save') }}</Button>
        </div>
    </form>
</template>
