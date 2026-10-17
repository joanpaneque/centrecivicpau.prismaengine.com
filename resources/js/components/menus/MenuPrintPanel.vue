<script setup lang="ts">
import { Check, FileDown, Loader2 } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n';
import { xsrfToken } from '@/lib/http';

/**
 * Print a set menu or a carta: pick the design and the languages, check the live A4
 * preview, and get one PDF with a page per language. The chosen design is remembered on
 * the menu or carta. Used inline in the assistant chat and inside the print dialog of the
 * back office.
 */
const props = withDefaults(defineProps<{ menuId: number; menuName: string; kind?: 'menu' | 'carta'; template?: string | null; languages?: string[] | null; compact?: boolean }>(), {
    kind: 'menu',
    template: null,
    languages: null,
    compact: false,
});
const emit = defineEmits<{ template: [template: string] }>();

const TEMPLATES = [
    { id: 'classic', icon: '✨', colors: ['#00056a', '#b08d57'] },
    { id: 'halloween', icon: '🎃', colors: ['#ea580c', '#2e1065'] },
    { id: 'christmas', icon: '🎄', colors: ['#b91c1c', '#166534'] },
    { id: 'easter', icon: '🐣', colors: ['#a78bfa', '#6ee7b7'] },
    { id: 'kids', icon: '🎈', colors: ['#3b82f6', '#f59e0b'] },
] as const;

/** Each language named in itself, so whoever prints recognises it. */
const LANGUAGES = [
    { id: 'es', name: 'Español' },
    { id: 'ca', name: 'Català' },
    { id: 'fr', name: 'Français' },
    { id: 'en', name: 'English' },
    { id: 'de', name: 'Deutsch' },
    { id: 'ru', name: 'Русский' },
    { id: 'it', name: 'Italiano' },
    { id: 'nl', name: 'Nederlands' },
] as const;

const PAGE_W = 794;
const PAGE_H = 1123;

const template = ref<string>(props.template ?? 'classic');
const selected = ref<string[]>(props.languages?.length ? [...props.languages] : ['ca', 'es']);
const previewLang = ref(selected.value[0] ?? 'ca');
const loadingPreview = ref(true);
const generating = ref(false);
const frameBox = ref<HTMLElement | null>(null);
const scale = ref(0.4);
let observer: ResizeObserver | null = null;

const ordered = computed(() => LANGUAGES.map((l) => l.id).filter((id) => selected.value.includes(id)));
const base = computed(() => `/gestion/${props.kind === 'carta' ? 'cartas' : 'menus'}/${props.menuId}`);
const previewUrl = computed(() => `${base.value}/vista?template=${template.value}&lang=${previewLang.value}`);
const allSelected = computed(() => selected.value.length === LANGUAGES.length);

onMounted(() => {
    nextTick(() => {
        if (frameBox.value) {
            observer = new ResizeObserver(() => {
                scale.value = (frameBox.value?.clientWidth ?? PAGE_W) / PAGE_W;
            });
            observer.observe(frameBox.value);
        }
    });
});

onBeforeUnmount(() => observer?.disconnect());

watch(previewUrl, () => (loadingPreview.value = true));

async function pickTemplate(id: string): Promise<void> {
    template.value = id;

    try {
        await fetch(`${base.value}/plantilla`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ template: id }),
        });
        emit('template', id);
    } catch {
        // Only the remembered choice is lost; printing still uses the selected design.
    }
}

function toggleLanguage(id: string): void {
    if (selected.value.includes(id)) {
        if (previewLang.value !== id) {
            previewLang.value = id;

            return;
        }

        if (selected.value.length > 1) {
            selected.value = selected.value.filter((l) => l !== id);
            previewLang.value = ordered.value[0];
        }

        return;
    }

    selected.value = [...selected.value, id];
    previewLang.value = id;
}

function toggleAll(): void {
    selected.value = allSelected.value ? [previewLang.value] : LANGUAGES.map((l) => l.id);
}

async function generate(): Promise<void> {
    if (generating.value) {
        return;
    }

    generating.value = true;

    try {
        const response = await fetch(`${base.value}/pdf?template=${template.value}&langs=${ordered.value.join(',')}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const url = URL.createObjectURL(await response.blob());

        if (!window.open(url, '_blank', 'noopener')) {
            const link = document.createElement('a');
            link.href = url;
            link.download = `${props.menuName || 'menu'}.pdf`;
            document.body.appendChild(link);
            link.click();
            link.remove();
        }

        window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch {
        toast.error(t('menuPrint.error'));
    } finally {
        generating.value = false;
    }
}
</script>

<template>
    <div class="grid gap-4" :class="compact ? 'sm:grid-cols-[12rem_1fr]' : 'md:grid-cols-[1fr_17rem]'">
        <div :class="compact ? '' : 'order-2 md:order-2'">
            <div
                ref="frameBox"
                class="relative mx-auto w-full overflow-hidden rounded-lg border bg-slate-100 shadow-inner"
                :class="compact ? 'max-w-[12rem]' : 'max-w-[17rem]'"
                :style="{ height: `${PAGE_H * scale}px` }"
            >
                <iframe
                    :key="previewUrl"
                    :src="previewUrl"
                    :title="t('menuPrint.preview')"
                    loading="lazy"
                    class="pointer-events-none origin-top-left border-0 bg-white"
                    :style="{ width: `${PAGE_W}px`, height: `${PAGE_H}px`, transform: `scale(${scale})` }"
                    @load="loadingPreview = false"
                />
                <div v-if="loadingPreview" class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-white/80 p-2 text-center text-xs text-slate-600">
                    <Loader2 class="size-6 animate-spin" /> {{ t('menuPrint.loadingPreview') }}
                </div>
            </div>
        </div>

        <div class="space-y-4" :class="compact ? '' : 'order-1 md:order-1'">
            <div>
                <p class="mb-2 text-sm font-semibold">{{ t('menuPrint.design') }}</p>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                    <button
                        v-for="option in TEMPLATES"
                        :key="option.id"
                        type="button"
                        class="relative flex flex-col items-center gap-1 overflow-hidden rounded-2xl border-2 bg-white px-1 pt-2.5 pb-2 text-xs font-semibold transition active:scale-95 dark:bg-slate-900"
                        :class="template === option.id ? 'border-[#00056a] ring-2 ring-[#00056a]/30' : 'border-slate-200'"
                        @click="pickTemplate(option.id)"
                    >
                        <span class="text-2xl leading-none">{{ option.icon }}</span>
                        {{ t(`menuPrint.templates.${option.id}`) }}
                        <span class="mt-1 flex h-1.5 w-full">
                            <span class="flex-1" :style="{ background: option.colors[0] }" />
                            <span class="flex-1" :style="{ background: option.colors[1] }" />
                        </span>
                        <Check v-if="template === option.id" class="absolute top-1 right-1 size-4 text-[#00056a]" />
                    </button>
                </div>
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-sm font-semibold">{{ t('menuPrint.languages') }}</p>
                    <button type="button" class="text-sm font-semibold text-[#00056a] dark:text-blue-300" @click="toggleAll">
                        {{ allSelected ? t('menuPrint.none') : t('menuPrint.all') }}
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <button
                        v-for="language in LANGUAGES"
                        :key="language.id"
                        type="button"
                        class="relative flex h-11 items-center justify-center gap-1 rounded-xl border-2 text-sm font-semibold transition active:scale-95"
                        :class="selected.includes(language.id) ? 'border-[#00056a] bg-[#00056a] text-white' : 'border-slate-200 bg-white text-slate-700 dark:bg-slate-900 dark:text-slate-200'"
                        @click="toggleLanguage(language.id)"
                    >
                        <Check v-if="selected.includes(language.id)" class="size-4" />
                        {{ language.name }}
                        <span v-if="previewLang === language.id" class="absolute -top-2 right-1 rounded-full bg-amber-400 px-1.5 text-[10px] font-bold text-amber-950">{{ t('menuPrint.previewing') }}</span>
                    </button>
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ t(kind === 'carta' ? 'menuPrint.languagesHelpCarta' : 'menuPrint.languagesHelp') }}</p>
            </div>

            <Button class="h-14 w-full bg-[#00056a] text-base" :disabled="generating || !ordered.length" @click="generate">
                <Loader2 v-if="generating" class="size-5 animate-spin" />
                <FileDown v-else class="size-5" />
                {{ generating ? t('menuPrint.generating') : t(kind === 'carta' ? 'menuPrint.generateCarta' : 'menuPrint.generate', { count: ordered.length }) }}
            </Button>
        </div>
    </div>
</template>
