<script setup lang="ts">
import { CalendarDays, ChevronDown, ExternalLink, FileText, Link2, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { CartaPreview, FamilyPreview, MenuPreview } from '@/assistant/api';
import MenuPrintPanel from '@/components/menus/MenuPrintPanel.vue';
import { t, tl, tr } from '@/i18n';
import { formatMoney } from '@/pos/money';

/**
 * Card shown in the assistant chat for what it just made: a family with its products, or a
 * set menu or carta with the real printed page right there (design, languages and PDF).
 * `latest` opens the print panel; older cards keep it folded.
 */
const props = withDefaults(defineProps<{ preview: FamilyPreview | MenuPreview | CartaPreview; latest?: boolean }>(), { latest: false });

const TEMPLATE_ICONS: Record<string, string> = { classic: '✨', halloween: '🎃', christmas: '🎄', easter: '🐣', kids: '🎈' };
const printable = computed(() => props.preview.type !== 'family');
const template = ref(props.preview.type !== 'family' ? props.preview.template || 'classic' : 'classic');
const printing = ref(props.latest || (props.preview.type !== 'family' && !!props.preview.print));
const printLanguages = computed(() => (props.preview.type !== 'family' ? (props.preview.print?.languages ?? null) : null));

const color = computed(() => (props.preview.type === 'carta' ? '#00056a' : props.preview.color || '#00056a'));
const text = computed(() => readable(color.value));
const pdfUrl = '/gestion/carta/pdf';
const manageUrl = computed(() => ({ set_menu: '/gestion/menus', carta: '/gestion/cartas', family: '/gestion/carta' })[props.preview.type]);

const schedule = computed(() => {
    if (props.preview.type !== 'set_menu') {
        return '';
    }

    const menu = props.preview;

    if (menu.scheduleType === 'weekdays') {
        const names = tl('common.weekdaysLong');

        return menu.weekdays.map((d) => names[d - 1] ?? '').join(', ');
    }

    if (menu.scheduleType === 'dates') {
        return [menu.startsOn, menu.endsOn].filter(Boolean).map((d) => new Date(`${d}T00:00:00`).toLocaleDateString()).join(' → ');
    }

    return t('studio.everyDay');
});

function readable(hex: string): string {
    const value = hex.replace('#', '');

    if (!/^[0-9a-f]{6}$/i.test(value)) {
        return '#ffffff';
    }

    const [r, g, b] = [0, 2, 4].map((i) => {
        const c = parseInt(value.slice(i, i + 2), 16) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.179 ? '#0f172a' : '#ffffff';
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl border bg-white shadow-sm dark:bg-slate-900">
        <!-- Family -->
        <template v-if="preview.type === 'family'">
            <div class="flex items-center gap-3 px-4 py-3" :style="{ background: color, color: text }">
                <img v-if="preview.photoUrl" :src="preview.photoUrl" alt="" class="size-12 rounded-xl object-cover" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-lg font-bold">{{ tr(preview.name) }}</p>
                    <p class="text-xs opacity-80">{{ t('studio.productsCount', { count: preview.products.length }) }}<template v-if="!preview.active"> · {{ t('studio.hidden') }}</template></p>
                </div>
            </div>
            <ul class="divide-y">
                <li v-for="product in preview.products" :key="product.id" class="flex items-center gap-2 px-4 py-2.5" :class="product.active ? '' : 'opacity-50'">
                    <span class="min-w-0 flex-1 truncate font-medium">{{ tr(product.name) }}</span>
                    <span v-if="product.soldOut" class="rounded bg-red-100 px-1.5 text-xs font-semibold text-red-700">{{ t('order.soldOut') }}</span>
                    <span v-if="product.price !== null" class="font-semibold tabular-nums">{{ formatMoney(product.price) }}</span>
                    <span v-else class="rounded bg-amber-100 px-1.5 text-xs font-semibold text-amber-800">{{ t('order.unpriced') }}</span>
                </li>
                <li v-if="!preview.products.length" class="px-4 py-3 text-sm text-slate-500">{{ t('common.empty') }}</li>
            </ul>
        </template>

        <!-- Carta: header, its dishes with prices, and the printed page itself -->
        <template v-else-if="preview.type === 'carta'">
            <div class="flex items-center gap-3 px-4 py-3" :style="{ background: color, color: text }">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-lg font-bold">{{ tr(preview.name) }}</p>
                    <p class="flex flex-wrap items-center gap-x-1 text-xs opacity-90">
                        <template v-if="preview.subtitle">{{ tr(preview.subtitle) }} · </template>
                        {{ TEMPLATE_ICONS[template] ?? '' }} {{ t(`menuPrint.templates.${template}`) }}<template v-if="!preview.active"> · {{ t('studio.inactive') }}</template>
                    </p>
                </div>
            </div>
            <div v-if="printing" class="p-3">
                <MenuPrintPanel compact kind="carta" :menu-id="preview.id" :menu-name="tr(preview.name)" :template="template" :languages="printLanguages" @template="(value) => (template = value)" />
            </div>
            <div v-else class="divide-y">
                <div v-for="(section, index) in preview.sections" :key="index" class="px-4 py-2.5">
                    <p class="mb-1 text-xs font-bold tracking-wide text-[#00056a] uppercase dark:text-blue-300">{{ tr(section.name) }}</p>
                    <div v-for="(item, i) in section.items" :key="i" class="flex items-baseline gap-2 py-0.5 text-sm">
                        <span class="min-w-0 flex-1">
                            <span class="font-medium">{{ tr(item.name) }}</span>
                            <span v-if="item.description" class="block text-xs text-slate-500 italic">{{ tr(item.description) }}</span>
                        </span>
                        <Link2 v-if="item.fromCatalog" class="size-3.5 shrink-0 text-slate-400" :aria-label="t('studio.priceFromCatalog')" />
                        <span v-if="item.price !== null" class="font-semibold tabular-nums">{{ formatMoney(item.price) }}</span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Set menu: header and the printed page itself -->
        <template v-else>
            <div class="flex items-center gap-3 px-4 py-3" :style="{ background: color, color: text }">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-lg font-bold">{{ tr(preview.name) }}</p>
                    <p class="flex flex-wrap items-center gap-x-1 text-xs opacity-90">
                        <CalendarDays class="size-3.5" /> {{ schedule }}<template v-if="!preview.active"> · {{ t('studio.inactive') }}</template>
                        · {{ TEMPLATE_ICONS[template] ?? '' }} {{ t(`menuPrint.templates.${template}`) }}
                    </p>
                </div>
                <span class="shrink-0 rounded-full bg-white/90 px-3 py-1 text-base font-bold text-slate-900">{{ formatMoney(preview.price) }}</span>
            </div>
            <div v-if="printing" class="p-3">
                <MenuPrintPanel compact :menu-id="preview.id" :menu-name="tr(preview.name)" :template="template" :languages="printLanguages" @template="(value) => (template = value)" />
            </div>
            <div v-else class="space-y-2 px-4 py-3 text-sm">
                <div v-for="(section, index) in preview.sections" :key="index">
                    <span class="font-semibold uppercase" :style="{ color }">{{ tr(section.name) }}:</span>
                    {{ section.items.map((item) => tr(item.name)).join(', ') }}
                </div>
            </div>
        </template>

        <div class="grid grid-cols-2 gap-2 border-t bg-slate-50 p-2 dark:bg-slate-800/50">
            <button
                v-if="printable"
                type="button"
                class="flex h-12 items-center justify-center gap-2 rounded-xl text-sm font-semibold active:scale-[0.98]"
                :class="printing ? 'border bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-100' : 'bg-[#00056a] text-white'"
                @click="printing = !printing"
            >
                <component :is="printing ? ChevronDown : Printer" class="size-5" :class="printing ? 'rotate-180' : ''" />
                {{ printing ? t('studio.hidePrint') : t('studio.printMenu') }}
            </button>
            <a v-else :href="pdfUrl" target="_blank" rel="noopener" class="flex h-12 items-center justify-center gap-2 rounded-xl bg-[#00056a] text-sm font-semibold text-white active:scale-[0.98]">
                <FileText class="size-5" /> {{ t('studio.openCartaPdf') }}
            </a>
            <a :href="manageUrl" target="_blank" rel="noopener" class="flex h-12 items-center justify-center gap-2 rounded-xl border bg-white text-sm font-semibold text-slate-700 active:scale-[0.98] dark:bg-slate-900 dark:text-slate-100">
                <ExternalLink class="size-5" /> {{ t('studio.openInAdmin') }}
            </a>
        </div>
    </div>
</template>
