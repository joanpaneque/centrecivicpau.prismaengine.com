<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Copy, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import Field from '@/components/admin/Field.vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import SelectInput from '@/components/admin/SelectInput.vue';
import TransInput from '@/components/admin/TransInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { tl, useI18n } from '@/i18n';
import { centsToInput, formatDate, money, parseMoney, toTrans } from '@/lib/format';
import { setMenus as setMenusRoute } from '@/routes/admin';
import { destroy, duplicate, store, update } from '@/routes/admin/set-menus';

type Trans = Record<string, string>;
type Item = { id: number | null; productId: number | null; name: Trans | null; destinationId: number | null; supplement: number };
type Section = { id: number | null; name: Trans; choices: number; course: number | null; items: Item[] };
type Menu = {
    id: number;
    name: Trans;
    includes: Trans | null;
    price: number;
    vatRate: number;
    color: string | null;
    scheduleType: 'always' | 'weekdays' | 'dates';
    weekdays: number[];
    startsOn: string | null;
    endsOn: string | null;
    active: boolean;
    availableToday: boolean;
    sections: Section[];
};

const props = defineProps<{
    menus: Menu[];
    products: { id: number; name: Trans; category_id: number }[];
    destinations: { id: number; name: Trans | string }[];
}>();

const { t, tr } = useI18n();

type FormItem = { id: number | null; mode: 'product' | 'free'; productId: number | null; name: { ca: string; es: string }; destinationId: number | null; supplement: string };
type FormSection = { id: number | null; name: { ca: string; es: string }; choices: number; course: number | null; items: FormItem[] };

const open = ref(false);
const editing = ref<Menu | null>(null);
const priceInput = ref('');
const form = useForm({
    name: { ca: '', es: '' },
    includes: { ca: '', es: '' },
    vatRate: 10,
    color: '#00056a',
    scheduleType: 'weekdays' as Menu['scheduleType'],
    weekdays: [1, 2, 3, 4, 5] as number[],
    startsOn: '' as string,
    endsOn: '' as string,
    active: true,
    sections: [] as FormSection[],
});

const productOptions = computed(() => props.products.map((p) => ({ value: p.id as number | null, label: tr(p.name) })));
const destinationOptions = computed(() => props.destinations.map((d) => ({ value: d.id as number | null, label: tr(d.name) })));
const courseOptions = computed(() => [1, 2, 3].map((c) => ({ value: c as number | null, label: t(`order.courses.${c}`) })));
const scheduleOptions = computed(() => (['always', 'weekdays', 'dates'] as const).map((value) => ({ value, label: t(`admin.setMenus.schedules.${value}`) })));
const vatOptions = [0, 4, 5, 10, 21].map((rate) => ({ value: rate, label: `${rate}%` }));

function defaultSections(): FormSection[] {
    const names = ['first', 'second', 'dessert', 'drink'] as const;

    return names.map((key, index) => ({
        id: null,
        name: { ca: caDefault(key), es: esDefault(key) },
        choices: 1,
        course: index < 3 ? index + 1 : null,
        items: [],
    }));
}

function caDefault(key: string): string {
    return ({ first: 'Primers', second: 'Segons', dessert: 'Postres', drink: 'Beguda' } as Record<string, string>)[key];
}

function esDefault(key: string): string {
    return ({ first: 'Primeros', second: 'Segundos', dessert: 'Postres', drink: 'Bebida' } as Record<string, string>)[key];
}

function openMenu(menu: Menu | null): void {
    editing.value = menu;
    form.clearErrors();
    form.name = toTrans(menu?.name);
    form.includes = toTrans(menu?.includes);
    priceInput.value = centsToInput(menu?.price ?? null);
    form.vatRate = menu?.vatRate ?? 10;
    form.color = menu?.color ?? '#00056a';
    form.scheduleType = menu?.scheduleType ?? 'weekdays';
    form.weekdays = [...(menu?.weekdays ?? [1, 2, 3, 4, 5])];
    form.startsOn = menu?.startsOn ?? '';
    form.endsOn = menu?.endsOn ?? '';
    form.active = menu?.active ?? true;
    form.sections = menu
        ? menu.sections.map((s) => ({
              id: s.id,
              name: toTrans(s.name),
              choices: s.choices,
              course: s.course,
              items: s.items.map((i) => ({
                  id: i.id,
                  mode: i.productId ? 'product' : 'free',
                  productId: i.productId,
                  name: toTrans(i.name),
                  destinationId: i.destinationId,
                  supplement: i.supplement ? centsToInput(i.supplement) : '',
              })),
          }))
        : defaultSections();
    open.value = true;
}

function toggleWeekday(day: number): void {
    const index = form.weekdays.indexOf(day);

    if (index >= 0) {
        form.weekdays.splice(index, 1);
    } else {
        form.weekdays.push(day);
    }
}

function moveSection(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= form.sections.length) {
        return;
    }

    const [section] = form.sections.splice(index, 1);
    form.sections.splice(target, 0, section);
}

function save(): void {
    form.transform((data) => ({
        ...data,
        price: parseMoney(priceInput.value),
        includes: data.includes.ca || data.includes.es ? data.includes : null,
        startsOn: data.startsOn || null,
        endsOn: data.endsOn || null,
        sections: data.sections.map((s) => ({
            id: s.id,
            name: s.name,
            choices: s.choices,
            course: s.course,
            items: s.items.map((i) => ({
                id: i.id,
                productId: i.mode === 'product' ? i.productId : null,
                name: i.mode === 'free' ? i.name : null,
                destinationId: i.destinationId,
                supplement: parseMoney(i.supplement),
            })),
        })),
    }));

    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (editing.value) {
        form.patch(update.url(editing.value.id), options);
    } else {
        form.post(store.url(), options);
    }
}

function itemLabel(item: Item): string {
    if (item.productId) {
        return tr(props.products.find((p) => p.id === item.productId)?.name ?? item.name);
    }

    return tr(item.name);
}

function scheduleLabel(menu: Menu): string {
    if (menu.scheduleType === 'weekdays') {
        const names = tl('common.weekdays');

        return menu.weekdays.map((d) => names[d - 1]).join(' · ');
    }

    if (menu.scheduleType === 'dates') {
        return `${formatDate(menu.startsOn)} – ${formatDate(menu.endsOn)}`;
    }

    return t('admin.setMenus.schedules.always');
}

const deleting = ref<Menu | null>(null);
const deleteOpen = computed({ get: () => deleting.value !== null, set: (v) => !v && (deleting.value = null) });

function remove(): void {
    if (deleting.value) {
        router.delete(destroy.url(deleting.value.id), {
            preserveScroll: true,
            onFinish: () => {
                deleting.value = null;
                open.value = false;
            },
        });
    }
}

defineOptions({ layout: { breadcrumbs: [{ title: 'Menús del dia', href: setMenusRoute() }] } });
</script>

<template>
    <Head :title="t('admin.setMenus.title')" />
    <PageHeader :title="t('admin.setMenus.title')" :description="t('admin.setMenus.description')">
        <Button @click="openMenu(null)"><Plus class="size-4" /> {{ t('admin.setMenus.new') }}</Button>
    </PageHeader>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div
            v-for="menu in menus"
            :key="menu.id"
            class="flex flex-col rounded-xl border bg-card p-4"
            :class="{ 'opacity-60': !menu.active }"
            :style="{ borderTopColor: menu.color ?? undefined, borderTopWidth: '4px' }"
        >
            <div class="flex items-start justify-between gap-2">
                <div>
                    <div class="font-semibold">{{ tr(menu.name) }}</div>
                    <div class="text-lg tabular-nums">{{ money(menu.price) }}</div>
                </div>
                <Badge :variant="menu.availableToday ? 'default' : 'secondary'">
                    {{ menu.availableToday ? t('admin.setMenus.availableToday') : t('admin.setMenus.notToday') }}
                </Badge>
            </div>
            <div class="mt-1 text-xs text-muted-foreground">{{ scheduleLabel(menu) }}</div>
            <p v-if="menu.includes" class="mt-1 text-xs text-muted-foreground">{{ t('setMenu.includes') }}: {{ tr(menu.includes) }}</p>
            <div class="mt-3 flex-1 space-y-2 text-sm">
                <div v-for="section in menu.sections" :key="section.id ?? 0">
                    <div class="text-xs font-medium text-muted-foreground uppercase">{{ tr(section.name) }}</div>
                    <div class="text-sm">{{ section.items.map(itemLabel).join(', ') || '—' }}</div>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <Button size="sm" variant="outline" @click="openMenu(menu)"><Pencil class="size-4" /> {{ t('common.edit') }}</Button>
                <Button size="sm" variant="ghost" @click="router.post(duplicate.url(menu.id), {}, { preserveScroll: true })">
                    <Copy class="size-4" /> {{ t('common.duplicate') }}
                </Button>
            </div>
        </div>
    </div>
    <p v-if="menus.length === 0" class="py-12 text-center text-sm text-muted-foreground">{{ t('common.empty') }}</p>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[94vh] overflow-y-auto sm:max-w-4xl">
            <form class="space-y-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{ editing ? t('common.edit') : t('admin.setMenus.new') }}</DialogTitle>
                </DialogHeader>

                <TransInput v-model="form.name" :label="t('common.name')" :error="form.errors['name.ca'] || form.errors.name" />
                <TransInput v-model="form.includes" :label="t('admin.setMenus.includes')" />

                <div class="grid gap-4 sm:grid-cols-4">
                    <Field :label="t('common.price')" :error="(form.errors as Record<string, string>).price">
                        <Input v-model="priceInput" inputmode="decimal" required />
                    </Field>
                    <Field :label="t('common.vat')">
                        <SelectInput v-model="form.vatRate" :options="vatOptions" />
                    </Field>
                    <Field :label="t('common.color')">
                        <input v-model="form.color" type="color" class="h-9 w-16 cursor-pointer rounded-md border" />
                    </Field>
                    <Label class="flex items-center gap-2 self-end pb-2"><Checkbox v-model="form.active" /> {{ t('common.active') }}</Label>
                </div>

                <div class="grid gap-4 rounded-lg border p-3 sm:grid-cols-[14rem_1fr]">
                    <Field :label="t('admin.setMenus.schedule')">
                        <SelectInput v-model="form.scheduleType" :options="scheduleOptions" />
                    </Field>
                    <div v-if="form.scheduleType === 'weekdays'" class="flex flex-wrap items-end gap-1">
                        <button
                            v-for="(day, index) in tl('common.weekdays')"
                            :key="index"
                            type="button"
                            class="size-9 rounded-full border text-sm"
                            :class="form.weekdays.includes(index + 1) ? 'border-primary bg-primary text-primary-foreground' : ''"
                            @click="toggleWeekday(index + 1)"
                        >
                            {{ day }}
                        </button>
                    </div>
                    <div v-else-if="form.scheduleType === 'dates'" class="grid grid-cols-2 gap-2">
                        <Field :label="t('common.from')"><Input v-model="form.startsOn" type="date" /></Field>
                        <Field :label="t('common.to')" :error="form.errors.endsOn"><Input v-model="form.endsOn" type="date" /></Field>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium">{{ t('admin.setMenus.sections') }}</h3>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="form.sections.push({ id: null, name: { ca: '', es: '' }, choices: 1, course: null, items: [] })"
                        >
                            <Plus class="size-4" /> {{ t('admin.setMenus.addSection') }}
                        </Button>
                    </div>

                    <div v-for="(section, sIndex) in form.sections" :key="sIndex" class="space-y-3 rounded-lg border p-3">
                        <div class="flex items-end gap-2">
                            <TransInput v-model="section.name" :label="t('admin.setMenus.sectionName')" class="flex-1" />
                            <Field :label="t('admin.setMenus.choices')" class="w-24">
                                <Input v-model.number="section.choices" type="number" min="1" max="10" />
                            </Field>
                            <Field :label="t('admin.setMenus.course')" class="w-28">
                                <SelectInput v-model="section.course" :placeholder="t('order.noCourse')" :options="courseOptions" />
                            </Field>
                            <div class="flex">
                                <Button type="button" variant="ghost" size="icon" @click="moveSection(sIndex, -1)"><ArrowUp class="size-4" /></Button>
                                <Button type="button" variant="ghost" size="icon" @click="moveSection(sIndex, 1)"><ArrowDown class="size-4" /></Button>
                                <Button type="button" variant="ghost" size="icon" class="text-destructive" @click="form.sections.splice(sIndex, 1)">
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div v-for="(item, iIndex) in section.items" :key="iIndex" class="grid items-end gap-2 rounded-md bg-muted/40 p-2 sm:grid-cols-[8rem_1fr_10rem_6rem_auto]">
                            <Field>
                                <SelectInput
                                    v-model="item.mode"
                                    :options="[
                                        { value: 'product', label: t('admin.setMenus.fromCatalog') },
                                        { value: 'free', label: t('admin.setMenus.freeText') },
                                    ]"
                                />
                            </Field>
                            <SelectInput v-if="item.mode === 'product'" v-model="item.productId" :placeholder="'—'" :options="productOptions" />
                            <div v-else class="grid grid-cols-2 gap-1">
                                <Input v-model="item.name.ca" placeholder="CA" />
                                <Input v-model="item.name.es" placeholder="ES" />
                            </div>
                            <SelectInput v-model="item.destinationId" :placeholder="t('admin.catalog.inheritDestination')" :options="destinationOptions" />
                            <Input v-model="item.supplement" inputmode="decimal" :placeholder="t('admin.setMenus.supplement')" />
                            <Button type="button" variant="ghost" size="icon" @click="section.items.splice(iIndex, 1)"><Trash2 class="size-4" /></Button>
                        </div>

                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="section.items.push({ id: null, mode: 'product', productId: null, name: { ca: '', es: '' }, destinationId: null, supplement: '' })"
                        >
                            <Plus class="size-4" /> {{ t('admin.setMenus.addItem') }}
                        </Button>
                    </div>
                </div>

                <p v-if="Object.keys(form.errors).length" class="text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>

                <DialogFooter class="gap-2">
                    <Button v-if="editing" type="button" variant="ghost" class="mr-auto text-destructive" @click="deleting = editing">
                        <Trash2 class="size-4" /> {{ t('common.delete') }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">{{ t('common.save') }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :title="t('common.delete')"
        :description="deleting ? tr(deleting.name) : ''"
        :confirm-label="t('common.delete')"
        @confirm="remove"
    />
</template>
