<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Copy, KeyRound, Plus, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { apiTokens as apiTokensRoute } from '@/routes/admin';
import { destroy, store } from '@/routes/admin/api-tokens';

type Token = { id: number; name: string; abilities: string[]; lastUsedAt: string | null; expiresAt: string | null; createdAt: string | null };

defineProps<{ tokens: Token[]; abilities: string[]; baseUrl: string }>();

const { t } = useI18n();

const newToken = ref<string | null>(null);
const stop = router.on('flash', (event) => {
    const flash = (event as CustomEvent).detail?.flash as { newToken?: string } | undefined;

    if (flash?.newToken) {
        newToken.value = flash.newToken;
    }
});
onBeforeUnmount(stop);

const createOpen = ref(false);
const form = useForm({ name: '', abilities: ['reservations:read'] as string[], expiresAt: '' });

function toggleAbility(ability: string): void {
    const index = form.abilities.indexOf(ability);

    if (index >= 0) {
        form.abilities.splice(index, 1);
    } else {
        form.abilities.push(ability);
    }
}

function submit(): void {
    form.transform((data) => ({ ...data, expiresAt: data.expiresAt || null })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
            form.reset();
        },
    });
}

async function copy(): Promise<void> {
    if (newToken.value) {
        await navigator.clipboard.writeText(newToken.value);
        toast.success(t('common.copied'));
    }
}

const revoking = ref<Token | null>(null);
const revokeOpen = computed({ get: () => revoking.value !== null, set: (v) => !v && (revoking.value = null) });

function revoke(): void {
    if (revoking.value) {
        router.delete(destroy.url(revoking.value.id), { preserveScroll: true, onFinish: () => (revoking.value = null) });
    }
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Accés API', href: apiTokensRoute() }] } });
</script>

<template>
    <Head :title="t('admin.api.title')" />
    <PageHeader :title="t('admin.api.title')" :description="t('admin.api.description')">
        <Button @click="createOpen = true"><Plus class="size-4" /> {{ t('admin.api.newToken') }}</Button>
    </PageHeader>

    <div class="mb-6 grid gap-3 rounded-xl border bg-muted/30 p-4 text-sm md:grid-cols-2">
        <div>
            <div class="font-medium">{{ t('nav.reservations') }}</div>
            <code class="text-xs">GET/POST {{ baseUrl }}/v1/reservations</code><br />
            <code class="text-xs">POST {{ baseUrl }}/v1/reservations/{uuid}/cancel</code>
        </div>
        <div>
            <div class="font-medium">{{ t('admin.time.inspection') }}</div>
            <code class="text-xs">GET {{ baseUrl }}/inspeccion/v1/trabajadores</code><br />
            <code class="text-xs">GET {{ baseUrl }}/inspeccion/v1/registros?from=&amp;to=&amp;worker=&amp;format=json|pdf|csv|xlsx</code>
        </div>
        <p class="text-xs text-muted-foreground md:col-span-2">Authorization: Bearer &lt;token&gt;</p>
    </div>

    <div class="space-y-2">
        <div v-for="token in tokens" :key="token.id" class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3">
            <KeyRound class="size-5 text-muted-foreground" />
            <div class="flex-1">
                <div class="font-medium">{{ token.name }}</div>
                <div class="mt-1 flex flex-wrap gap-1">
                    <Badge v-for="ability in token.abilities" :key="ability" variant="secondary">{{ ability }}</Badge>
                </div>
            </div>
            <div class="text-xs text-muted-foreground">
                {{ t('admin.api.lastUsed') }}: {{ token.lastUsedAt ? formatDateTime(token.lastUsedAt) : t('admin.devices.never') }}
                <div v-if="token.expiresAt">{{ t('admin.api.expires') }}: {{ formatDateTime(token.expiresAt) }}</div>
            </div>
            <Button variant="ghost" size="icon" class="text-destructive" @click="revoking = token"><Trash2 class="size-4" /></Button>
        </div>
        <p v-if="tokens.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
    </div>

    <Dialog v-model:open="createOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ t('admin.api.newToken') }}</DialogTitle>
                    <DialogDescription>{{ t('admin.time.inspectionHelp') }}</DialogDescription>
                </DialogHeader>
                <Field :label="t('common.name')" :error="form.errors.name"><Input v-model="form.name" required /></Field>
                <Field :label="t('admin.api.abilities')" :error="form.errors.abilities">
                    <div class="space-y-2">
                        <Label v-for="ability in abilities" :key="ability" class="flex items-center gap-2 font-normal">
                            <Checkbox :model-value="form.abilities.includes(ability)" @update:model-value="toggleAbility(ability)" />
                            <code>{{ ability }}</code>
                        </Label>
                    </div>
                </Field>
                <Field :label="t('admin.api.expires')" :error="form.errors.expiresAt"><Input v-model="form.expiresAt" type="date" /></Field>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing || form.abilities.length === 0">{{ t('common.create') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="newToken !== null" @update:open="(v) => !v && (newToken = null)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('admin.api.newToken') }}</DialogTitle>
                <DialogDescription>{{ t('admin.api.tokenShown') }}</DialogDescription>
            </DialogHeader>
            <div class="rounded-lg border bg-muted p-3 font-mono text-sm break-all">{{ newToken }}</div>
            <DialogFooter>
                <Button @click="copy"><Copy class="size-4" /> {{ t('common.copy') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="revokeOpen"
        destructive
        :title="t('admin.api.revoke')"
        :description="revoking?.name"
        :confirm-label="t('admin.api.revoke')"
        @confirm="revoke"
    />
</template>
