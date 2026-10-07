<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChefHat, Monitor, QrCode, Receipt, Tablet } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/i18n';
import { api, HttpError } from '@/lib/http';
import { setKv } from '@/pos/store';
import type { DeviceInfo, DeviceType } from '@/pos/types';

const emit = defineEmits<{ registered: [] }>();
const page = usePage();
const role = computed(() => (page.props.auth as { user?: { role?: string } }).user?.role ?? 'staff');

const types: { value: DeviceType; icon: typeof Tablet }[] = [
    { value: 'tablet', icon: Tablet },
    { value: 'cashier', icon: Receipt },
    { value: 'kds', icon: ChefHat },
    { value: 'clock', icon: QrCode },
];

const type = ref<DeviceType>(role.value === 'kitchen' ? 'kds' : 'tablet');
const name = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

function allowed(value: DeviceType): boolean {
    return role.value === 'admin' || value === 'tablet' || (value === 'kds' && role.value === 'kitchen');
}

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        const response = await api<{ device: DeviceInfo }>('POST', '/tpv/api/device', {
            name: name.value || t(`device.types.${type.value}`),
            type: type.value,
        });
        setKv('device', response.device);
        emit('registered');
    } catch (e) {
        error.value = e instanceof HttpError ? e.message : t('common.offlineOnly');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-svh items-center justify-center bg-slate-100 p-4">
        <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl sm:p-8">
            <div class="mb-6 flex items-center gap-4">
                <img src="/images/logo-centre-civic-icon.png" alt="" class="size-14" />
                <div>
                    <h1 class="text-2xl font-semibold text-[#00056a]">{{ t('device.setupTitle') }}</h1>
                    <p class="text-sm text-slate-500">{{ t('device.setupDescription') }}</p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <button
                    v-for="item in types"
                    :key="item.value"
                    type="button"
                    :disabled="!allowed(item.value)"
                    class="flex items-start gap-3 rounded-xl border-2 p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-40"
                    :class="type === item.value ? 'border-[#00056a] bg-[#00056a]/5' : 'border-slate-200 hover:border-slate-300'"
                    @click="type = item.value"
                >
                    <component :is="item.icon" class="mt-0.5 size-6 shrink-0 text-[#00056a]" />
                    <span>
                        <span class="block font-semibold">{{ t(`device.types.${item.value}`) }}</span>
                        <span class="block text-sm text-slate-500">{{ t(`device.typeHelp.${item.value}`) }}</span>
                        <span v-if="!allowed(item.value)" class="mt-1 block text-xs text-amber-600">{{ t('device.adminOnly') }}</span>
                    </span>
                </button>
            </div>

            <div class="mt-6 grid gap-2">
                <Label for="device-name">{{ t('device.name') }}</Label>
                <Input id="device-name" v-model="name" :placeholder="t('device.namePlaceholder')" class="h-12 text-lg" />
            </div>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <Button class="mt-6 h-12 w-full bg-[#00056a] text-lg hover:bg-[#00056a]/90" :disabled="busy" @click="submit">
                <Monitor class="size-5" />
                {{ t('device.register') }}
            </Button>
        </div>
    </div>
</template>
