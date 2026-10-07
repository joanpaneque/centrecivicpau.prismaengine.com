<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    EllipsisVertical,
    KeyRound,
    Pencil,
    Plus,
    Power,
    QrCode,
    RectangleEllipsis,
    Trash2,
} from '@lucide/vue';
import QRCode from 'qrcode';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { api } from '@/lib/http';
import { users as usersRoute } from '@/routes/admin';
import {
    active as activeRoute,
    destroy,
    password as passwordRoute,
    pin as pinRoute,
    qr as qrRoute,
    store,
    update,
} from '@/routes/admin/users';

type Role = 'admin' | 'staff' | 'kitchen';

type AdminUser = {
    id: number;
    name: string;
    email: string | null;
    role: Role;
    locale: 'ca' | 'es';
    color: string | null;
    taxId: string | null;
    active: boolean;
    isAdmin: boolean;
    hasPin: boolean;
    mustChangePassword: boolean;
    createdAt: string | null;
};

defineProps<{ users: AdminUser[] }>();

const { t } = useI18n();
const page = usePage();
const currentUserId = computed(() => (page.props.auth as { user: { id: number } }).user.id);

const roleOptions = computed(() => (['staff', 'admin', 'kitchen'] as Role[]).map((value) => ({ value, label: t(`admin.users.roles.${value}`) })));
const localeOptions = computed(() => [
    { value: 'ca' as const, label: t('common.catalan') },
    { value: 'es' as const, label: t('common.spanish') },
]);

const editing = ref<AdminUser | null>(null);
const formOpen = ref(false);
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'staff' as Role,
    locale: 'ca' as 'ca' | 'es',
    color: '#00056a',
    tax_id: '',
    pin: '',
});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    formOpen.value = true;
}

function openEdit(user: AdminUser): void {
    editing.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email ?? '';
    form.role = user.role;
    form.locale = user.locale;
    form.color = user.color ?? '#00056a';
    form.tax_id = user.taxId ?? '';
    formOpen.value = true;
}

function submit(): void {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false) };

    if (editing.value) {
        form.transform((data) => ({ name: data.name, email: data.email || null, role: data.role, locale: data.locale, color: data.color, tax_id: data.tax_id || null }))
            .patch(update.url(editing.value.id), options);
    } else {
        form.transform((data) => ({ ...data, email: data.email || null, password: data.password || null, tax_id: data.tax_id || null, pin: data.pin || null }))
            .post(store.url(), options);
    }
}

const pinUser = ref<AdminUser | null>(null);
const pinForm = useForm({ pin: '' });

function openPin(user: AdminUser): void {
    pinUser.value = user;
    pinForm.reset();
    pinForm.clearErrors();
}

function savePin(clear = false): void {
    if (!pinUser.value) {
        return;
    }

    pinForm.transform((data) => ({ pin: clear ? null : data.pin })).patch(pinRoute.url(pinUser.value.id), {
        preserveScroll: true,
        onSuccess: () => (pinUser.value = null),
    });
}

const passwordUser = ref<AdminUser | null>(null);
const passwordForm = useForm({ password: '', password_confirmation: '' });

function openPassword(user: AdminUser): void {
    passwordUser.value = user;
    passwordForm.reset();
    passwordForm.clearErrors();
}

function savePassword(): void {
    if (!passwordUser.value) {
        return;
    }

    passwordForm.patch(passwordRoute.url(passwordUser.value.id), {
        preserveScroll: true,
        onSuccess: () => (passwordUser.value = null),
    });
}

const qrUser = ref<AdminUser | null>(null);
const qrImage = ref('');
const qrUrl = ref('');
const qrLoading = ref(false);

async function loadQr(user: AdminUser, regenerate = false): Promise<void> {
    qrUser.value = user;
    qrLoading.value = true;

    try {
        const response = await api<{ url: string }>('POST', qrRoute.url(user.id), { regenerate });
        qrUrl.value = response.url;
        qrImage.value = await QRCode.toDataURL(response.url, { width: 320, margin: 1, color: { dark: '#00056a' } });
    } finally {
        qrLoading.value = false;
    }
}

function printQr(): void {
    const win = window.open('', '_blank', 'width=420,height=560');

    if (!win || !qrUser.value) {
        return;
    }

    win.document.write(`<html><head><title>${qrUser.value.name}</title></head><body style="font-family:sans-serif;text-align:center;padding:24px">
        <h2 style="margin:0 0 12px">${qrUser.value.name}</h2><img src="${qrImage.value}" style="width:280px"/><p style="font-size:12px;color:#555">Centre Cívic Pau · TPV</p>
        <script>window.onload=()=>{window.print();}<\/script></body></html>`);
    win.document.close();
}

function toggleActive(user: AdminUser): void {
    router.patch(activeRoute.url(user.id), {}, { preserveScroll: true });
}

const deleting = ref<AdminUser | null>(null);
const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (value) => {
        if (!value) {
            deleting.value = null;
        }
    },
});

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    router.delete(destroy.url(deleting.value.id), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuaris', href: usersRoute() }],
    },
});
</script>

<template>
    <Head :title="t('admin.users.title')" />

    <PageHeader :title="t('admin.users.title')" :description="t('admin.users.description')">
        <Button @click="openCreate">
            <Plus class="size-4" />
            {{ t('admin.users.new') }}
        </Button>
    </PageHeader>

    <section class="overflow-hidden rounded-xl border">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/40 text-xs text-muted-foreground uppercase">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ t('common.name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('common.email') }}</th>
                        <th class="px-4 py-3 font-medium">{{ t('admin.users.role') }}</th>
                        <th class="px-4 py-3 font-medium">PIN</th>
                        <th class="px-4 py-3 font-medium">{{ t('common.status') }}</th>
                        <th class="px-4 py-3 text-right font-medium">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="border-t"
                        :class="{ 'opacity-50': !user.active }"
                    >
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 font-medium">
                                <span class="size-3 rounded-full" :style="{ background: user.color ?? '#94a3b8' }" />
                                {{ user.name }}
                                <Badge v-if="user.id === currentUserId" variant="outline">{{ t('admin.users.you') }}</Badge>
                            </div>
                            <div v-if="user.taxId" class="text-xs text-muted-foreground">{{ user.taxId }}</div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ user.email ?? t('admin.users.noEmail') }}
                            <Badge v-if="user.mustChangePassword" variant="outline" class="ml-1">
                                {{ t('admin.users.mustChange') }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="user.role === 'admin' ? 'default' : 'secondary'">
                                {{ t(`admin.users.roles.${user.role}`) }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span :class="user.hasPin ? 'text-emerald-600' : 'text-muted-foreground'">
                                {{ user.hasPin ? t('admin.users.pinSet') : t('admin.users.pinMissing') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            {{ user.active ? t('common.active') : t('common.inactive') }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="icon" :title="t('admin.users.qr')" @click="loadQr(user)">
                                    <QrCode class="size-4" />
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon">
                                            <EllipsisVertical class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem @click="openEdit(user)">
                                            <Pencil /> {{ t('common.edit') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem @click="openPin(user)">
                                            <RectangleEllipsis /> {{ t('admin.users.setPin') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem v-if="user.email" @click="openPassword(user)">
                                            <KeyRound /> {{ t('admin.users.resetPassword') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem :disabled="user.id === currentUserId" @click="toggleActive(user)">
                                            <Power /> {{ user.active ? t('admin.users.deactivate') : t('admin.users.activate') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            :disabled="user.id === currentUserId"
                                            @click="deleting = user"
                                        >
                                            <Trash2 /> {{ t('common.delete') }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <Dialog v-model:open="formOpen">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ editing ? t('common.edit') : t('admin.users.new') }}</DialogTitle>
                </DialogHeader>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field :label="t('common.name')" :error="form.errors.name" class="sm:col-span-2">
                        <Input v-model="form.name" required autocomplete="off" />
                    </Field>
                    <Field :label="t('admin.users.role')" :error="form.errors.role">
                        <SelectInput
                            v-model="form.role"
                            :options="roleOptions.map((o) => ({ ...o, disabled: editing?.id === currentUserId && o.value !== 'admin' }))"
                        />
                    </Field>
                    <Field :label="t('admin.users.locale')" :error="form.errors.locale">
                        <SelectInput v-model="form.locale" :options="localeOptions" />
                    </Field>
                    <Field :label="t('common.email')" :error="form.errors.email" :help="t('admin.users.noEmail')" class="sm:col-span-2">
                        <Input v-model="form.email" type="email" autocomplete="off" />
                    </Field>
                    <template v-if="!editing && form.email">
                        <Field :label="t('admin.users.temporaryPassword')" :error="form.errors.password">
                            <PasswordInput v-model="form.password" autocomplete="new-password" />
                        </Field>
                        <Field :label="t('common.passwordConfirm')">
                            <PasswordInput v-model="form.password_confirmation" autocomplete="new-password" />
                        </Field>
                    </template>
                    <Field v-if="!editing" :label="t('admin.users.pin')" :error="form.errors.pin">
                        <Input v-model="form.pin" inputmode="numeric" maxlength="4" pattern="\d{4}" autocomplete="off" />
                    </Field>
                    <Field :label="t('admin.users.taxId')" :error="form.errors.tax_id">
                        <Input v-model="form.tax_id" autocomplete="off" />
                    </Field>
                    <Field :label="t('common.color')" :error="form.errors.color">
                        <input v-model="form.color" type="color" class="h-9 w-16 cursor-pointer rounded-md border" />
                    </Field>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="formOpen = false">{{ t('common.cancel') }}</Button>
                    <Button type="submit" :disabled="form.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="pinUser !== null" @update:open="(v) => !v && (pinUser = null)">
        <DialogContent class="sm:max-w-sm">
            <form class="space-y-4" @submit.prevent="savePin()">
                <DialogHeader>
                    <DialogTitle>{{ t('admin.users.setPin') }}</DialogTitle>
                    <DialogDescription>{{ pinUser?.name }}</DialogDescription>
                </DialogHeader>
                <Field :label="t('admin.users.pin')" :error="pinForm.errors.pin">
                    <Input
                        v-model="pinForm.pin"
                        inputmode="numeric"
                        maxlength="4"
                        pattern="\d{4}"
                        required
                        class="text-center text-2xl tracking-[0.6em]"
                        autocomplete="off"
                    />
                </Field>
                <DialogFooter class="gap-2">
                    <Button v-if="pinUser?.hasPin" type="button" variant="outline" @click="savePin(true)">
                        {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="pinForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="passwordUser !== null" @update:open="(v) => !v && (passwordUser = null)">
        <DialogContent class="sm:max-w-sm">
            <form class="space-y-4" @submit.prevent="savePassword">
                <DialogHeader>
                    <DialogTitle>{{ t('admin.users.resetPassword') }}</DialogTitle>
                    <DialogDescription>{{ passwordUser?.name }} · {{ t('admin.users.mustChange') }}</DialogDescription>
                </DialogHeader>
                <Field :label="t('admin.users.temporaryPassword')" :error="passwordForm.errors.password">
                    <PasswordInput v-model="passwordForm.password" required autocomplete="new-password" />
                </Field>
                <Field :label="t('common.passwordConfirm')">
                    <PasswordInput v-model="passwordForm.password_confirmation" required autocomplete="new-password" />
                </Field>
                <DialogFooter>
                    <Button type="submit" :disabled="passwordForm.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="qrUser !== null" @update:open="(v) => !v && (qrUser = null)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>{{ t('admin.users.qr') }} · {{ qrUser?.name }}</DialogTitle>
                <DialogDescription>{{ t('admin.users.qrHelp') }}</DialogDescription>
            </DialogHeader>
            <div class="flex justify-center">
                <img v-if="qrImage && !qrLoading" :src="qrImage" alt="QR" class="size-64 rounded-lg border" />
                <div v-else class="size-64 animate-pulse rounded-lg bg-muted" />
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" :disabled="qrLoading" @click="qrUser && loadQr(qrUser, true)">
                    {{ t('admin.users.regenerateQr') }}
                </Button>
                <Button :disabled="!qrImage" @click="printQr">{{ t('admin.users.printQr') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('common.delete')"
        :description="deleting ? t('admin.users.confirmDelete', { name: deleting.name }) : ''"
        :confirm-label="t('common.delete')"
        @confirm="confirmDelete"
    />
</template>
