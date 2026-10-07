<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import TransInput from '@/components/admin/TransInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import { toTrans } from '@/lib/format';
import { settings as settingsRoute } from '@/routes/admin';
import { clockSecret, update } from '@/routes/admin/settings';

const props = defineProps<{ settings: Record<string, unknown> & { logo_url: string | null } }>();
const { t } = useI18n();

const s = props.settings;
const str = (key: string): string => (s[key] === null || s[key] === undefined ? '' : String(s[key]));
const num = (key: string): number => Number(s[key] ?? 0);

const form = useForm({
    business_name: str('business_name'),
    issuer_name: str('issuer_name'),
    issuer_tax_id: str('issuer_tax_id'),
    issuer_address: str('issuer_address'),
    issuer_postal_code: str('issuer_postal_code'),
    issuer_city: str('issuer_city'),
    issuer_province: str('issuer_province'),
    issuer_phone: str('issuer_phone'),
    issuer_email: str('issuer_email'),
    ticket_footer: toTrans(s.ticket_footer),
    terrace_surcharge_percent: num('terrace_surcharge_percent'),
    show_zero_surcharge: Boolean(s.show_zero_surcharge),
    default_vat_rate: num('default_vat_rate'),
    default_locale: (str('default_locale') || 'ca') as 'ca' | 'es',
    reservation_lead_minutes: num('reservation_lead_minutes'),
    clock_qr_seconds: num('clock_qr_seconds'),
    clock_out_reminder_minutes: num('clock_out_reminder_minutes'),
    off_shift_tolerance_minutes: num('off_shift_tolerance_minutes'),
    invoice_series_code: str('invoice_series_code'),
    remove_logo: false,
});

const logo = ref<File | null>(null);
const logoPreview = ref<string | null>(props.settings.logo_url);

function pickLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file) {
        logo.value = file;
        logoPreview.value = URL.createObjectURL(file);
        form.remove_logo = false;
    }
}

function submit(): void {
    form.transform((data) => ({ ...data, logo: logo.value })).post(update.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (logo.value = null),
    });
}

const secretOpen = ref(false);

function regenerateSecret(): void {
    router.post(clockSecret.url(), {}, { preserveScroll: true, onFinish: () => (secretOpen.value = false) });
}

const vatOptions = [0, 4, 5, 10, 21].map((rate) => ({ value: rate, label: `${rate}%` }));

defineOptions({ layout: { breadcrumbs: [{ title: 'Configuració', href: settingsRoute() }] } });
</script>

<template>
    <Head :title="t('admin.settings.title')" />
    <PageHeader :title="t('admin.settings.title')" :description="t('admin.settings.description')" />

    <form class="max-w-4xl space-y-8" @submit.prevent="submit">
        <section class="space-y-4 rounded-xl border p-4">
            <h2 class="font-medium">{{ t('admin.settings.business') }}</h2>
            <Field :label="t('admin.settings.businessName')" :error="form.errors.business_name">
                <Input v-model="form.business_name" required />
            </Field>
            <div class="flex items-center gap-4">
                <div class="flex size-24 items-center justify-center overflow-hidden rounded-lg border bg-white p-1">
                    <img v-if="logoPreview && !form.remove_logo" :src="logoPreview" alt="" class="max-h-full max-w-full object-contain" />
                </div>
                <div class="space-y-2">
                    <Label>{{ t('admin.settings.logo') }}</Label>
                    <input type="file" accept="image/*" class="block text-sm" @change="pickLogo" />
                    <Label class="flex items-center gap-2 text-xs font-normal"><Checkbox v-model="form.remove_logo" /> {{ t('admin.catalog.removePhoto') }}</Label>
                </div>
            </div>
        </section>

        <section class="space-y-4 rounded-xl border p-4">
            <h2 class="font-medium">{{ t('admin.settings.issuer') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <Field :label="t('admin.settings.issuerName')" :error="form.errors.issuer_name"><Input v-model="form.issuer_name" required /></Field>
                <Field :label="t('admin.settings.issuerTaxId')" :error="form.errors.issuer_tax_id"><Input v-model="form.issuer_tax_id" required /></Field>
                <Field :label="t('admin.settings.address')" :error="form.errors.issuer_address" class="sm:col-span-2"><Input v-model="form.issuer_address" required /></Field>
                <Field :label="t('admin.settings.postalCode')" :error="form.errors.issuer_postal_code"><Input v-model="form.issuer_postal_code" required /></Field>
                <Field :label="t('admin.settings.city')" :error="form.errors.issuer_city"><Input v-model="form.issuer_city" required /></Field>
                <Field :label="t('admin.settings.province')" :error="form.errors.issuer_province"><Input v-model="form.issuer_province" required /></Field>
                <Field :label="t('common.phone')" :error="form.errors.issuer_phone"><Input v-model="form.issuer_phone" /></Field>
                <Field :label="t('common.email')" :error="form.errors.issuer_email" class="sm:col-span-2"><Input v-model="form.issuer_email" type="email" /></Field>
            </div>
            <TransInput v-model="form.ticket_footer" :label="t('admin.settings.ticketFooter')" multiline />
            <Field :label="t('admin.settings.invoiceSeries')" :error="form.errors.invoice_series_code" class="max-w-40">
                <Input v-model="form.invoice_series_code" maxlength="5" required />
            </Field>
        </section>

        <section class="space-y-4 rounded-xl border p-4">
            <h2 class="font-medium">{{ t('nav.cashier') }} · {{ t('nav.floor') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <Field :label="t('admin.settings.terraceSurcharge')" :error="form.errors.terrace_surcharge_percent">
                    <Input v-model.number="form.terrace_surcharge_percent" type="number" min="0" max="100" step="0.5" />
                </Field>
                <Field :label="t('admin.settings.defaultVat')"><SelectInput v-model="form.default_vat_rate" :options="vatOptions" /></Field>
                <Field :label="t('admin.settings.defaultLocale')">
                    <SelectInput
                        v-model="form.default_locale"
                        :options="[
                            { value: 'ca', label: t('common.catalan') },
                            { value: 'es', label: t('common.spanish') },
                        ]"
                    />
                </Field>
                <Field :label="t('admin.settings.reservationLead')" class="sm:col-span-2">
                    <Input v-model.number="form.reservation_lead_minutes" type="number" min="0" />
                </Field>
            </div>
            <Label class="flex items-center gap-2"><Checkbox v-model="form.show_zero_surcharge" /> {{ t('admin.settings.showZeroSurcharge') }}</Label>
        </section>

        <section class="space-y-4 rounded-xl border p-4">
            <h2 class="font-medium">{{ t('admin.time.title') }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <Field :label="t('admin.settings.clockQrSeconds')" :error="form.errors.clock_qr_seconds">
                    <Input v-model.number="form.clock_qr_seconds" type="number" min="15" max="600" />
                </Field>
                <Field :label="t('admin.settings.clockOutReminder')"><Input v-model.number="form.clock_out_reminder_minutes" type="number" min="0" /></Field>
                <Field :label="t('admin.settings.offShiftTolerance')"><Input v-model.number="form.off_shift_tolerance_minutes" type="number" min="0" /></Field>
            </div>
            <Button type="button" variant="outline" @click="secretOpen = true"><KeyRound class="size-4" /> {{ t('admin.settings.regenerateClockSecret') }}</Button>
        </section>

        <div class="sticky bottom-0 -mx-4 border-t bg-background/95 px-4 py-3 backdrop-blur">
            <Button type="submit" size="lg" :disabled="form.processing">{{ form.processing ? t('common.saving') : t('common.save') }}</Button>
        </div>
    </form>

    <ConfirmDialog
        v-model:open="secretOpen"
        :title="t('admin.settings.regenerateClockSecret')"
        :description="t('admin.settings.regenerateClockSecretHelp')"
        @confirm="regenerateSecret"
    />
</template>
