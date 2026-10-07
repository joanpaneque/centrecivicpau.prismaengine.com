<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ExternalLink, FileText, Plus, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import DocumentScanner from '@/components/admin/DocumentScanner.vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import Pagination from '@/components/admin/Pagination.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import TextArea from '@/components/admin/TextArea.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/i18n';
import { centsToInput, formatDate, formatDateTime, money, parseMoney } from '@/lib/format';
import { suppliers as suppliersRoute } from '@/routes/admin';
import { destroy, store, update } from '@/routes/admin/suppliers';

type Invoice = {
    id: number;
    supplier: string | null;
    invoiceDate: string | null;
    amount: number | null;
    notes: string | null;
    mimeType: string;
    originalName: string | null;
    size: number;
    ocrStatus: string;
    uploadedBy: string | null;
    createdAt: string | null;
    fileUrl: string;
};

const props = defineProps<{
    invoices: { data: Invoice[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { supplier?: string; from?: string; to?: string; status?: string };
    suppliers: string[];
}>();

const { t } = useI18n();

const filters = reactive({
    supplier: props.filters.supplier ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    status: (props.filters.status ?? null) as string | null,
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            suppliersRoute.url(),
            { supplier: filters.supplier || undefined, from: filters.from || undefined, to: filters.to || undefined, status: filters.status || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

const createOpen = ref(false);
const file = ref<File | null>(null);
const filePreview = ref('');
const amountInput = ref('');
const createForm = useForm({ supplier: '', invoiceDate: '', notes: '' });

function openCreate(): void {
    file.value = null;
    filePreview.value = '';
    amountInput.value = '';
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
}

function scanned(result: File): void {
    file.value = result;

    if (filePreview.value) {
        URL.revokeObjectURL(filePreview.value);
    }

    filePreview.value = result.type.startsWith('image/') ? URL.createObjectURL(result) : '';
}

onBeforeUnmount(() => filePreview.value && URL.revokeObjectURL(filePreview.value));

function submitCreate(): void {
    createForm
        .transform((data) => ({
            ...data,
            file: file.value,
            supplier: data.supplier || null,
            invoiceDate: data.invoiceDate || null,
            notes: data.notes || null,
            amount: amountInput.value ? parseMoney(amountInput.value) : null,
        }))
        .post(store.url(), { forceFormData: true, preserveScroll: true, onSuccess: () => (createOpen.value = false) });
}

const viewing = ref<Invoice | null>(null);
const editAmount = ref('');
const editForm = useForm({ supplier: '', invoiceDate: '', notes: '' });

function openView(invoice: Invoice): void {
    viewing.value = invoice;
    editForm.supplier = invoice.supplier ?? '';
    editForm.invoiceDate = invoice.invoiceDate ?? '';
    editForm.notes = invoice.notes ?? '';
    editAmount.value = centsToInput(invoice.amount);
    editForm.clearErrors();
}

function submitEdit(): void {
    if (!viewing.value) {
        return;
    }

    editForm
        .transform((data) => ({
            supplier: data.supplier || null,
            invoiceDate: data.invoiceDate || null,
            notes: data.notes || null,
            amount: editAmount.value ? parseMoney(editAmount.value) : null,
        }))
        .patch(update.url(viewing.value.id), { preserveScroll: true, onSuccess: () => (viewing.value = null) });
}

const deleting = ref<Invoice | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function remove(): void {
    if (deleting.value) {
        router.delete(destroy.url(deleting.value.id), {
            preserveScroll: true,
            onFinish: () => {
                deleting.value = null;
                viewing.value = null;
            },
        });
    }
}

function isPending(invoice: Invoice): boolean {
    return !invoice.supplier || invoice.amount === null;
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Factures de proveïdors', href: suppliersRoute() }] } });
</script>

<template>
    <Head :title="t('admin.suppliers.title')" />
    <PageHeader :title="t('admin.suppliers.title')" :description="t('admin.suppliers.description')">
        <Button size="lg" @click="openCreate"><Plus class="size-4" /> {{ t('admin.suppliers.new') }}</Button>
    </PageHeader>

    <div class="mb-4 flex flex-wrap gap-2">
        <Input v-model="filters.supplier" list="supplier-names" :placeholder="t('admin.suppliers.supplier')" class="max-w-56" />
        <datalist id="supplier-names">
            <option v-for="name in suppliers" :key="name" :value="name" />
        </datalist>
        <Input v-model="filters.from" type="date" class="max-w-40" />
        <Input v-model="filters.to" type="date" class="max-w-40" />
        <SelectInput
            v-model="filters.status"
            class="max-w-48"
            :placeholder="t('common.all')"
            :options="[
                { value: 'pending', label: t('admin.suppliers.pending') },
                { value: 'reviewed', label: t('admin.suppliers.reviewed') },
            ]"
        />
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <button
            v-for="invoice in invoices.data"
            :key="invoice.id"
            type="button"
            class="overflow-hidden rounded-xl border bg-card text-left transition hover:shadow-md"
            @click="openView(invoice)"
        >
            <div class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-muted">
                <img v-if="invoice.mimeType.startsWith('image/')" :src="invoice.fileUrl" alt="" loading="lazy" class="size-full object-cover object-top" />
                <FileText v-else class="size-12 text-muted-foreground" />
            </div>
            <div class="space-y-1 p-3">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate font-medium">{{ invoice.supplier ?? '—' }}</span>
                    <span class="text-sm tabular-nums">{{ invoice.amount !== null ? money(invoice.amount) : '' }}</span>
                </div>
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>{{ invoice.invoiceDate ? formatDate(invoice.invoiceDate) : formatDateTime(invoice.createdAt) }}</span>
                    <Badge :variant="isPending(invoice) ? 'outline' : 'secondary'">
                        {{ isPending(invoice) ? t('admin.suppliers.pending') : t('admin.suppliers.reviewed') }}
                    </Badge>
                </div>
            </div>
        </button>
    </div>
    <p v-if="invoices.data.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>
    <Pagination :links="invoices.links" />

    <Dialog v-model:open="createOpen">
        <DialogContent class="max-h-[94vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{ t('admin.suppliers.new') }}</DialogTitle>
            </DialogHeader>
            <DocumentScanner v-if="!file" @done="scanned" @cancel="createOpen = false" />
            <form v-else class="grid gap-4 md:grid-cols-[1fr_18rem]" @submit.prevent="submitCreate">
                <div class="flex items-start justify-center rounded-lg bg-muted p-2">
                    <img v-if="filePreview" :src="filePreview" alt="" class="max-h-[60vh] rounded" />
                    <div v-else class="flex flex-col items-center gap-2 p-8 text-sm"><FileText class="size-12" /> {{ file.name }}</div>
                </div>
                <div class="space-y-3">
                    <Field :label="t('admin.suppliers.supplier')" :error="createForm.errors.supplier">
                        <Input v-model="createForm.supplier" list="supplier-names" />
                    </Field>
                    <Field :label="t('admin.suppliers.issueDate')" :error="createForm.errors.invoiceDate">
                        <Input v-model="createForm.invoiceDate" type="date" />
                    </Field>
                    <Field :label="t('admin.suppliers.amount')" :error="(createForm.errors as Record<string, string>).amount">
                        <Input v-model="amountInput" inputmode="decimal" />
                    </Field>
                    <Field :label="t('common.notes')">
                        <TextArea v-model="createForm.notes" :rows="3" />
                    </Field>
                    <p class="text-xs text-muted-foreground">{{ t('admin.suppliers.ocrPending') }}</p>
                    <p class="text-sm text-destructive">{{ (createForm.errors as Record<string, string>).file }}</p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" @click="file = null">{{ t('admin.suppliers.retake') }}</Button>
                        <Button type="submit" class="flex-1" :disabled="createForm.processing">{{ t('common.save') }}</Button>
                    </div>
                    <progress v-if="createForm.progress" :value="createForm.progress.percentage" max="100" class="w-full" />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="viewing !== null" @update:open="(v) => !v && (viewing = null)">
        <DialogContent v-if="viewing" class="max-h-[94vh] overflow-y-auto sm:max-w-5xl">
            <DialogHeader>
                <DialogTitle>{{ viewing.supplier ?? viewing.originalName }}</DialogTitle>
            </DialogHeader>
            <div class="grid gap-4 md:grid-cols-[1fr_18rem]">
                <div class="overflow-auto rounded-lg bg-muted p-2">
                    <img v-if="viewing.mimeType.startsWith('image/')" :src="viewing.fileUrl" alt="" class="mx-auto" />
                    <iframe v-else :src="viewing.fileUrl" class="h-[70vh] w-full rounded" />
                </div>
                <form class="space-y-3" @submit.prevent="submitEdit">
                    <Field :label="t('admin.suppliers.supplier')"><Input v-model="editForm.supplier" list="supplier-names" /></Field>
                    <Field :label="t('admin.suppliers.issueDate')"><Input v-model="editForm.invoiceDate" type="date" /></Field>
                    <Field :label="t('admin.suppliers.amount')"><Input v-model="editAmount" inputmode="decimal" /></Field>
                    <Field :label="t('common.notes')"><TextArea v-model="editForm.notes" :rows="3" /></Field>
                    <div class="text-xs text-muted-foreground">
                        {{ viewing.uploadedBy }} · {{ formatDateTime(viewing.createdAt) }} · {{ Math.round(viewing.size / 1024) }} KB
                    </div>
                    <DialogFooter class="flex-col gap-2 sm:flex-col">
                        <Button type="submit" :disabled="editForm.processing">{{ t('common.save') }}</Button>
                        <Button variant="outline" as-child>
                            <a :href="viewing.fileUrl" target="_blank"><ExternalLink class="size-4" /> {{ t('admin.suppliers.file') }}</a>
                        </Button>
                        <Button type="button" variant="ghost" class="text-destructive" @click="deleting = viewing">
                            <Trash2 class="size-4" /> {{ t('common.delete') }}
                        </Button>
                    </DialogFooter>
                </form>
            </div>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('common.delete')"
        :description="deleting?.supplier ?? deleting?.originalName ?? ''"
        :confirm-label="t('common.delete')"
        @confirm="remove"
    />
</template>
