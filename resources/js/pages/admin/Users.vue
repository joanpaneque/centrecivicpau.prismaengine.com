<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import {
    EllipsisVertical,
    KeyRound,
    Pencil,
    Plus,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminUserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { users as adminUsers } from '@/routes/admin';

type UserRole = 'user' | 'admin';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    isAdmin: boolean;
    mustChangePassword: boolean;
    createdAt: string | null;
};

defineProps<{
    users: AdminUser[];
}>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);
const createDialogOpen = ref(false);
const togglingUserId = ref<number | null>(null);

const selectedUser = ref<AdminUser | null>(null);
const editDialogOpen = ref(false);
const resetDialogOpen = ref(false);
const deleteDialogOpen = ref(false);

function roleFor(user: AdminUser): UserRole {
    return user.isAdmin ? 'admin' : 'user';
}

function canChangeRole(user: AdminUser, role: UserRole): boolean {
    if (togglingUserId.value === user.id) {
        return false;
    }

    if (user.id === currentUserId.value && role === 'user') {
        return false;
    }

    return true;
}

function updateRole(user: AdminUser, role: string): void {
    if (role !== 'user' && role !== 'admin') {
        return;
    }

    const isAdmin = role === 'admin';

    if (user.isAdmin === isAdmin || !canChangeRole(user, role)) {
        return;
    }

    togglingUserId.value = user.id;

    router.patch(
        AdminUserController.updateAdmin.url(user.id),
        { is_admin: isAdmin ? 1 : 0 },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingUserId.value = null;
            },
        },
    );
}

function openEdit(user: AdminUser): void {
    selectedUser.value = user;
    editDialogOpen.value = true;
}

function openResetPassword(user: AdminUser): void {
    selectedUser.value = user;
    resetDialogOpen.value = true;
}

function openDelete(user: AdminUser): void {
    selectedUser.value = user;
    deleteDialogOpen.value = true;
}

function closeSelectedDialog(): void {
    editDialogOpen.value = false;
    resetDialogOpen.value = false;
    deleteDialogOpen.value = false;
    selectedUser.value = null;
}

function setEditDialogOpen(open: boolean): void {
    editDialogOpen.value = open;

    if (!open) {
        selectedUser.value = null;
    }
}

function setResetDialogOpen(open: boolean): void {
    resetDialogOpen.value = open;

    if (!open) {
        selectedUser.value = null;
    }
}

function setDeleteDialogOpen(open: boolean): void {
    deleteDialogOpen.value = open;

    if (!open) {
        selectedUser.value = null;
    }
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Administración',
                href: adminUsers(),
            },
            {
                title: 'Usuarios',
                href: adminUsers(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Usuarios"
                description="Gestiona los usuarios de la plataforma"
            />

            <Dialog v-model:open="createDialogOpen">
                <DialogTrigger as-child>
                    <Button>
                        <Plus class="size-4" />
                        Crear usuario
                    </Button>
                </DialogTrigger>

                <DialogContent class="sm:max-w-md">
                    <Form
                        v-bind="AdminUserController.store.form()"
                        class="space-y-6"
                        reset-on-success
                        :options="{ preserveScroll: true }"
                        @success="createDialogOpen = false"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader>
                            <div
                                class="mb-2 flex size-10 items-center justify-center rounded-full bg-muted"
                            >
                                <UserPlus
                                    class="size-5 text-muted-foreground"
                                />
                            </div>
                            <DialogTitle>Crear usuario</DialogTitle>
                            <DialogDescription>
                                Se creará con una contraseña temporal. En el
                                primer acceso deberá elegir una nueva.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="create-email"
                                    >Correo electrónico</Label
                                >
                                <Input
                                    id="create-email"
                                    type="email"
                                    name="email"
                                    required
                                    autocomplete="off"
                                    placeholder="usuario@ejemplo.com"
                                />
                                <InputError :message="errors.email" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="create-password"
                                    >Contraseña temporal</Label
                                >
                                <PasswordInput
                                    id="create-password"
                                    name="password"
                                    required
                                    autocomplete="new-password"
                                    placeholder="Contraseña temporal"
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="create-password-confirmation"
                                    >Confirmar contraseña</Label
                                >
                                <PasswordInput
                                    id="create-password-confirmation"
                                    name="password_confirmation"
                                    required
                                    autocomplete="new-password"
                                    placeholder="Confirmar contraseña"
                                />
                            </div>
                        </div>

                        <DialogFooter class="gap-2 sm:gap-2">
                            <DialogClose as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    Cancelar
                                </Button>
                            </DialogClose>
                            <Button type="submit" :disabled="processing">
                                Crear usuario
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>

        <section
            class="overflow-hidden rounded-xl border border-sidebar-border/70"
        >
            <div
                class="flex items-center justify-between border-b border-sidebar-border/70 px-4 py-3"
            >
                <h2 class="text-sm font-medium text-foreground">Usuaris</h2>
                <span class="text-xs text-muted-foreground"
                    >{{ users.length }} en total</span
                >
            </div>

            <div
                v-if="users.length === 0"
                class="px-4 py-12 text-center text-sm text-muted-foreground"
            >
                Todavía no hay usuarios.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/40">
                        <tr>
                            <th class="px-4 py-3 font-medium">Nombre</th>
                            <th class="px-4 py-3 font-medium">Correo</th>
                            <th class="px-4 py-3 font-medium">Rol</th>
                            <th class="px-4 py-3 font-medium">Estado</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Acciones
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="border-t border-sidebar-border/50"
                        >
                            <td class="px-4 py-3 font-medium text-foreground">
                                {{ user.name }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ user.email }}
                            </td>
                            <td class="px-4 py-3">
                                <Select
                                    :model-value="roleFor(user)"
                                    :disabled="togglingUserId === user.id"
                                    :title="
                                        user.id === currentUserId &&
                                        user.isAdmin
                                            ? 'No puedes quitarte el permiso de administrador a ti mismo'
                                            : undefined
                                    "
                                    @update:model-value="
                                        (role) => updateRole(user, String(role))
                                    "
                                >
                                    <SelectTrigger
                                        class="w-[11.5rem]"
                                        :aria-label="`Rol de ${user.name}`"
                                    >
                                        <SelectValue placeholder="Rol" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            value="user"
                                            :disabled="
                                                !canChangeRole(user, 'user')
                                            "
                                        >
                                            Usuario
                                        </SelectItem>
                                        <SelectItem value="admin">
                                            Administrador
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <Badge
                                        v-if="user.mustChangePassword"
                                        variant="outline"
                                    >
                                        Debe cambiar la contraseña
                                    </Badge>
                                    <span v-else class="text-muted-foreground"
                                        >Activo</span
                                    >
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            :aria-label="`Acciones de ${user.name}`"
                                        >
                                            <EllipsisVertical class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            @click="openEdit(user)"
                                        >
                                            <Pencil />
                                            Editar
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            @click="openResetPassword(user)"
                                        >
                                            <KeyRound />
                                            Restablecer contraseña
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            :disabled="
                                                user.id === currentUserId
                                            "
                                            @click="openDelete(user)"
                                        >
                                            <Trash2 />
                                            Eliminar
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog :open="editDialogOpen" @update:open="setEditDialogOpen">
            <DialogContent v-if="selectedUser" class="sm:max-w-md">
                <Form
                    v-bind="AdminUserController.update.form(selectedUser.id)"
                    class="space-y-6"
                    :options="{ preserveScroll: true }"
                    @success="closeSelectedDialog"
                    v-slot="{ errors, processing }"
                >
                    <DialogHeader>
                        <div
                            class="mb-2 flex size-10 items-center justify-center rounded-full bg-muted"
                        >
                            <Pencil class="size-5 text-muted-foreground" />
                        </div>
                        <DialogTitle>Editar usuario</DialogTitle>
                        <DialogDescription>
                            Actualiza el nombre y el correo de
                            {{ selectedUser.name }}.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="edit-name">Nombre</Label>
                            <Input
                                id="edit-name"
                                type="text"
                                name="name"
                                required
                                autocomplete="off"
                                :default-value="selectedUser.name"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="edit-email">Correo electrónico</Label>
                            <Input
                                id="edit-email"
                                type="email"
                                name="email"
                                required
                                autocomplete="off"
                                :default-value="selectedUser.email"
                            />
                            <InputError :message="errors.email" />
                        </div>
                    </div>

                    <DialogFooter class="gap-2 sm:gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" :disabled="processing">
                            Guardar cambios
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <Dialog :open="resetDialogOpen" @update:open="setResetDialogOpen">
            <DialogContent v-if="selectedUser" class="sm:max-w-md">
                <Form
                    v-bind="
                        AdminUserController.resetPassword.form(selectedUser.id)
                    "
                    class="space-y-6"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="closeSelectedDialog"
                    v-slot="{ errors, processing, reset, clearErrors }"
                >
                    <DialogHeader>
                        <div
                            class="mb-2 flex size-10 items-center justify-center rounded-full bg-muted"
                        >
                            <KeyRound class="size-5 text-muted-foreground" />
                        </div>
                        <DialogTitle>Restablecer contraseña</DialogTitle>
                        <DialogDescription>
                            Define una contraseña temporal para
                            {{ selectedUser.name }}. Al iniciar sesión deberá
                            elegir una nueva.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="reset-password"
                                >Contraseña temporal</Label
                            >
                            <PasswordInput
                                id="reset-password"
                                name="password"
                                required
                                autocomplete="new-password"
                                placeholder="Contraseña temporal"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="reset-password-confirmation"
                                >Confirmar contraseña</Label
                            >
                            <PasswordInput
                                id="reset-password-confirmation"
                                name="password_confirmation"
                                required
                                autocomplete="new-password"
                                placeholder="Confirmar contraseña"
                            />
                        </div>
                    </div>

                    <DialogFooter class="gap-2 sm:gap-2">
                        <DialogClose as-child>
                            <Button
                                type="button"
                                variant="outline"
                                @click="
                                    () => {
                                        clearErrors();
                                        reset();
                                    }
                                "
                            >
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" :disabled="processing">
                            Restablecer
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <Dialog :open="deleteDialogOpen" @update:open="setDeleteDialogOpen">
            <DialogContent v-if="selectedUser" class="sm:max-w-md">
                <Form
                    v-bind="AdminUserController.destroy.form(selectedUser.id)"
                    class="space-y-6"
                    :options="{ preserveScroll: true }"
                    @success="closeSelectedDialog"
                    v-slot="{ processing }"
                >
                    <DialogHeader>
                        <div
                            class="mb-2 flex size-10 items-center justify-center rounded-full bg-destructive/10"
                        >
                            <Trash2 class="size-5 text-destructive" />
                        </div>
                        <DialogTitle>Eliminar usuario</DialogTitle>
                        <DialogDescription>
                            Vas a eliminar a
                            <span class="font-medium text-foreground">{{
                                selectedUser.name
                            }}</span>
                            ({{ selectedUser.email }}). Esta acción no se puede
                            deshacer.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter class="gap-2 sm:gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="processing"
                        >
                            Eliminar usuario
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
