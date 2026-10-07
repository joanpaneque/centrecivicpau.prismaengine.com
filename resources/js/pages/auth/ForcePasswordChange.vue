<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import ForcePasswordChangeController from '@/actions/App/Http/Controllers/Auth/ForcePasswordChangeController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: {
        title: 'Elige una nueva contraseña',
        description:
            'Por seguridad, debes cambiar la contraseña temporal antes de continuar',
    },
});

const props = defineProps<{
    passwordRules: string;
}>();
</script>

<template>
    <Head title="Cambiar contraseña" />

    <Form
        v-bind="ForcePasswordChangeController.update.form()"
        :reset-on-success="['password', 'password_confirmation']"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="password">Nueva contraseña</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    placeholder="Nueva contraseña"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirmar contraseña</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    placeholder="Confirmar contraseña"
                />
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="force-password-change-button"
            >
                <Spinner v-if="processing" />
                Guardar contraseña
            </Button>
        </div>
    </Form>
</template>
