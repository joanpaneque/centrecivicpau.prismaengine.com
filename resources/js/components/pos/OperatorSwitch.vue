<script setup lang="ts">
import { ArrowLeft } from '@lucide/vue';
import { ref, watch } from 'vue';
import PinPad from '@/components/pos/PinPad.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { applyLocale, t } from '@/i18n';
import { api, HttpError, isNetworkError } from '@/lib/http';
import { pinDigest } from '@/pos/crypto';
import { setKv, sorted, state } from '@/pos/store';
import { pull } from '@/pos/sync';
import type { Staff } from '@/pos/types';

const open = defineModel<boolean>('open', { default: false });

const selected = ref<Staff | null>(null);
const pin = ref('');
const error = ref<string | null>(null);
const shake = ref(false);
const busy = ref(false);

watch(open, (value) => {
    if (value) {
        selected.value = null;
        pin.value = '';
        error.value = null;
    }
});

function choose(staff: Staff): void {
    selected.value = staff;
    pin.value = '';
    error.value = staff.pinDigest ? null : t('operator.noPin');
}

function fail(message: string): void {
    error.value = message;
    shake.value = true;
    setTimeout(() => {
        shake.value = false;
        pin.value = '';
    }, 400);
}

async function verify(value: string): Promise<void> {
    const staff = selected.value;

    if (!staff || busy.value) {
        return;
    }

    busy.value = true;

    try {
        await api('POST', '/tpv/api/switch-user', { userId: staff.id, pin: value });
        done(staff);
        void pull();
    } catch (e) {
        if (isNetworkError(e)) {
            // Offline: compare with the digest received at the last sync.
            if (staff.pinDigest && staff.pinDigest === pinDigest(staff.id, value)) {
                done(staff);
            } else {
                fail(t('operator.wrongPin'));
            }
        } else {
            fail(e instanceof HttpError ? e.message : t('operator.wrongPin'));
        }
    } finally {
        busy.value = false;
    }
}

function done(staff: Staff): void {
    setKv('operatorId', staff.id);
    applyLocale(staff.locale);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <button v-if="selected" type="button" class="rounded p-1 hover:bg-slate-100" @click="selected = null">
                        <ArrowLeft class="size-5" />
                    </button>
                    {{ selected ? t('operator.enterPin', { name: selected.name }) : t('operator.title') }}
                </DialogTitle>
                <DialogDescription class="sr-only">{{ t('operator.switch') }}</DialogDescription>
            </DialogHeader>

            <div v-if="!selected" class="grid max-h-[60vh] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3">
                <button
                    v-for="staff in sorted.staff.value.filter((s) => s.role !== 'kitchen')"
                    :key="staff.id"
                    type="button"
                    class="flex flex-col items-center gap-2 rounded-xl border-2 p-4 transition hover:border-[#00056a]"
                    :class="staff.id === (state.operatorId ?? state.me?.id) ? 'border-[#00056a] bg-[#00056a]/5' : 'border-slate-200'"
                    @click="choose(staff)"
                >
                    <span class="flex size-14 items-center justify-center rounded-full text-lg font-bold text-white" :style="{ background: staff.color || '#00056a' }">
                        {{ staff.name.slice(0, 2).toUpperCase() }}
                    </span>
                    <span class="text-center text-sm font-medium">{{ staff.name }}</span>
                </button>
            </div>

            <div v-else class="flex flex-col items-center gap-4 py-2">
                <PinPad v-if="selected.pinDigest" v-model="pin" :error="shake" @complete="verify" />
                <p v-if="error" class="text-center text-sm text-red-600">{{ error }}</p>
            </div>
        </DialogContent>
    </Dialog>
</template>
