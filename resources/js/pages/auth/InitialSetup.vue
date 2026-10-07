<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Field from '@/components/admin/Field.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { applyLocale, t, useI18n } from '@/i18n';
import { store } from '@/routes/initial-setup';

defineOptions({
    layout: {
        title: t('setup.title'),
        description: t('setup.description'),
    },
});

const { locale } = useI18n();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    locale: locale.value as 'ca' | 'es',
});

function submit(): void {
    form.post(store.url(), { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head :title="t('setup.title')" />

    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <Field :label="t('setup.name')" :error="form.errors.name">
            <Input v-model="form.name" required autofocus autocomplete="name" />
        </Field>
        <Field :label="t('common.email')" :error="form.errors.email">
            <Input v-model="form.email" type="email" required autocomplete="email" />
        </Field>
        <Field :label="t('common.password')" :error="form.errors.password">
            <PasswordInput v-model="form.password" required autocomplete="new-password" />
        </Field>
        <Field :label="t('common.passwordConfirm')">
            <PasswordInput v-model="form.password_confirmation" required autocomplete="new-password" />
        </Field>
        <Field :label="t('common.language')" :error="form.errors.locale">
            <SelectInput
                v-model="form.locale"
                :options="[
                    { value: 'ca', label: 'Català' },
                    { value: 'es', label: 'Castellano' },
                ]"
                @update:model-value="(v) => applyLocale(v as string)"
            />
        </Field>
        <Button type="submit" size="lg" class="mt-2 w-full" :disabled="form.processing">
            <Spinner v-if="form.processing" />
            {{ t('setup.submit') }}
        </Button>
    </form>
</template>
