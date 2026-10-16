<script setup lang="ts">
import { CalendarDays, ExternalLink, FileText, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { FamilyPreview, MenuPreview } from '@/assistant/api';
import MenuPrintDialog from '@/components/menus/MenuPrintDialog.vue';
import { t, tl, tr } from '@/i18n';
import { formatMoney } from '@/pos/money';

/**
 * Card shown in Carta amb IA for what the assistant just made: a family with its products
 * or a set menu laid out like the printed one. "Obrir" opens the printable PDF.
 */
const props = defineProps<{ preview: FamilyPreview | MenuPreview }>();

const TEMPLATE_ICONS: Record<string, string> = { classic: '✨', halloween: '🎃', christmas: '🎄', easter: '🐣', kids: '🎈' };
const printing = ref(false);
const template = ref(props.preview.type === 'set_menu' ? props.preview.template || 'classic' : 'classic');

const color = computed(() => props.preview.color || '#00056a');
const text = computed(() => readable(color.value));
const pdfUrl = '/gestion/carta/pdf';
const manageUrl = computed(() => (props.preview.type === 'set_menu' ? '/gestion/menus' : '/gestion/carta'));

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

        <!-- Set menu, laid out like the printed menu -->
        <template v-else>
            <div class="px-5 pt-5 pb-4 text-center" :style="{ borderBottom: `4px solid ${color}` }">
                <p class="font-serif text-2xl font-bold" :style="{ color }">{{ tr(preview.name) }}</p>
                <span class="mt-2 inline-block rounded-full px-4 py-1 text-lg font-bold" :style="{ background: color, color: text }">{{ formatMoney(preview.price) }}</span>
                <p v-if="preview.includes" class="mt-2 text-sm text-slate-600">{{ tr(preview.includes) }}</p>
                <p class="mt-2 flex items-center justify-center gap-1 text-xs text-slate-500">
                    <CalendarDays class="size-3.5" /> {{ schedule }}<template v-if="!preview.active"> · {{ t('studio.inactive') }}</template>
                    · {{ TEMPLATE_ICONS[template] ?? '' }} {{ t(`menuPrint.templates.${template}`) }}
                </p>
            </div>
            <div class="space-y-4 px-5 py-4 text-center">
                <div v-for="(section, index) in preview.sections" :key="index">
                    <p class="font-serif text-sm font-bold tracking-wider uppercase" :style="{ color }">{{ tr(section.name) }}</p>
                    <p v-if="section.choices > 1" class="text-[11px] text-slate-400">{{ t('setMenu.pick', { count: section.choices }) }}</p>
                    <p v-for="(item, i) in section.items" :key="i" class="mt-1 text-[15px]">
                        {{ tr(item.name) }}
                        <span v-if="item.supplement" class="text-xs font-semibold" :style="{ color }">+{{ formatMoney(item.supplement) }}</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-2 gap-2 border-t bg-slate-50 p-2 dark:bg-slate-800/50">
            <button
                v-if="preview.type === 'set_menu'"
                type="button"
                class="flex h-12 items-center justify-center gap-2 rounded-xl bg-[#00056a] text-sm font-semibold text-white active:scale-[0.98]"
                @click="printing = true"
            >
                <Printer class="size-5" /> {{ t('studio.printMenu') }}
            </button>
            <a v-else :href="pdfUrl" target="_blank" rel="noopener" class="flex h-12 items-center justify-center gap-2 rounded-xl bg-[#00056a] text-sm font-semibold text-white active:scale-[0.98]">
                <FileText class="size-5" /> {{ t('studio.openCartaPdf') }}
            </a>
            <a :href="manageUrl" target="_blank" rel="noopener" class="flex h-12 items-center justify-center gap-2 rounded-xl border bg-white text-sm font-semibold text-slate-700 active:scale-[0.98] dark:bg-slate-900 dark:text-slate-100">
                <ExternalLink class="size-5" /> {{ t('studio.openInAdmin') }}
            </a>
        </div>

        <MenuPrintDialog
            v-if="printing && preview.type === 'set_menu'"
            :menu-id="preview.id"
            :menu-name="tr(preview.name)"
            :template="template"
            @template="(value) => (template = value)"
            @close="printing = false"
        />
    </div>
</template>
