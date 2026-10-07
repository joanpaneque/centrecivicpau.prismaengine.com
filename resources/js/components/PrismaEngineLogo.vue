<script setup>
import { computed, ref } from 'vue';
import PyramidCanvas from '@/components/PyramidCanvas.vue';
import StepText from '@/components/StepText.vue';

const logoHovered = ref(false);

const props = defineProps({
    /** Sufijo de producto junto a «Prisma» (p. ej. Engine, Slides, Docs). */
    product: {
        type: String,
        default: 'Engine',
    },
    /** Color de acento (pirámide y sufijo del producto). */
    accentColor: {
        type: String,
        default: '#0040c1',
    },
    /** Reserva ancho fijo para los sufijos indicados (p. ej. Engine/Slides). */
    fixedProductLabels: {
        type: Array,
        default: null,
    },
    /** Escala lineal: altura = 150×size px; ancho = fit-content (pirámide + texto). */
    size: {
        type: Number,
        default: 1,
    },
    /** `brand`: azul + texto oscuro/accent; `white`: pirámide y texto en blanco. */
    variant: {
        type: String,
        default: 'brand',
        validator: (v) => v === 'brand' || v === 'white',
    },
    /**
     * Si es true: la pirámide solo gira en hover (como en el header).
     * Si es false: giro continuo (p. ej. splash), sin depender del hover.
     */
    spinWhenHovered: {
        type: Boolean,
        default: true,
    },
    /** Opacidad del canvas de la pirámide (0–1); útil para entradas en splash. */
    canvasOpacity: {
        type: Number,
        default: 1,
    },
    /** Opacidad del bloque de texto (0–1). */
    textOpacity: {
        type: Number,
        default: 1,
    },
    /** Duración CSS de transición de opacidad del canvas; 0 = sin transición. */
    canvasFadeMs: {
        type: Number,
        default: 0,
    },
    /** Duración CSS de transición de opacidad del texto. */
    textFadeMs: {
        type: Number,
        default: 0,
    },
    /**
     * Grosor del trazo de la pirámide (px). Por defecto coincide con PyramidCanvas (34).
     * El splash puede pasar un valor algo menor para un trazo más fino.
     */
    pyramidStrokeWidth: {
        type: Number,
        default: undefined,
    },
    /**
     * Texto un poco menos pesado (p. ej. solo en splash con variant `white`).
     */
    lighterTypography: {
        type: Boolean,
        default: false,
    },
    /** Splash: revelación del texto letra a letra (opacidad). */
    textRevealStagger: {
        type: Boolean,
        default: false,
    },
    /** Activa el fade-in por letra (junto con `textRevealStagger`). */
    textRevealActive: {
        type: Boolean,
        default: false,
    },
    /**
     * PyramidCanvas: arranque de giro rápido → lento (ms). 0 = giro uniforme.
     * Típico solo en splash.
     */
    autoSpinRampMs: {
        type: Number,
        default: 0,
    },
    /** Ms quietos antes del giro automático (p. ej. splash). */
    autoSpinDelayMs: {
        type: Number,
        default: 0,
    },
    /**
     * Solo splash: tipografía mucho más ligera (`font-light` + `font-extralight` en Engine).
     * Requiere `variant="white"`.
     */
    splashSofterAccent: {
        type: Boolean,
        default: false,
    },
    /**
     * Logo compacto (menos padding tipográfico). El hover del giro va siempre
     * en la raíz del componente, no en contenedores padre.
     */
    compact: {
        type: Boolean,
        default: false,
    },
});

const BASE_H = 150;
const CANVAS = 150;
/** Tamaño base de fuente (px) a `size === 1`; el real es `LABEL_FONT_PX * size`. */
const LABEL_FONT_PX = 90;

const rootStyle = computed(() => ({
    height: `${BASE_H * props.size}px`,
    '--logo-accent': props.accentColor,
}));

const canvasWrapStyle = computed(() => {
    const s = props.size;
    const base = {
        width: `${CANVAS * s}px`,
        height: `${CANVAS * s}px`,
        opacity: props.canvasOpacity,
    };

    if (props.canvasFadeMs > 0) {
        base.transition = `opacity ${props.canvasFadeMs}ms ease-out`;
    }

    return base;
});

const textColumnStyle = computed(() => {
    if (props.textRevealStagger) {
        return { opacity: 1 };
    }

    const base = { opacity: props.textOpacity };

    if (props.textFadeMs > 0) {
        base.transition = `opacity ${props.textFadeMs}ms ease-out`;
    }

    return base;
});

const labelStyle = computed(() => {
    const s = props.size;
    const fontPx = LABEL_FONT_PX * s;

    return {
        fontFamily: '"Lexend", ui-sans-serif, system-ui, sans-serif',
        fontSize: `${fontPx}px`,
        lineHeight: 1,
        letterSpacing: '-0.07em',
    };
});

const logoText = computed(() => `Prisma ${props.product}`);

const reserveLogoTexts = computed(
    () => props.fixedProductLabels?.map((label) => `Prisma ${label}`) ?? [],
);

const hasReservedWidth = computed(() => reserveLogoTexts.value.length > 0);

const effectivePyramidStrokeWidth = computed(() =>
    props.pyramidStrokeWidth != null ? props.pyramidStrokeWidth : 34,
);

const pyramidStrokeColor = computed(() =>
    props.variant === 'white' ? '#ffffff' : props.accentColor,
);

const baseCharClass = computed(() => {
    if (props.variant === 'white') {
        if (props.splashSofterAccent) {
            return 'font-light text-white';
        }

        const w = props.lighterTypography ? 'font-semibold' : 'font-bold';

        return `${w} text-white`;
    }

    return 'font-bold text-neutral-900 dark:text-neutral-100';
});

const accentCharClass = computed(() => {
    if (props.variant === 'white') {
        if (props.splashSofterAccent) {
            return 'font-extralight text-white';
        }

        const w = props.lighterTypography ? 'font-normal' : 'font-medium';

        return `${w} text-white`;
    }

    return 'font-medium text-[var(--logo-accent)]';
});

function onLogoEnter() {
    if (props.spinWhenHovered) {
        logoHovered.value = true;
    }
}

function onLogoLeave() {
    if (props.spinWhenHovered) {
        logoHovered.value = false;
    }
}
</script>

<template>
    <div
        class="group/prisma-logo flex w-fit max-w-full shrink-0 items-stretch overflow-hidden"
        :style="rootStyle"
        @mouseenter="onLogoEnter"
        @mouseleave="onLogoLeave"
    >
        <div
            class="flex min-h-0 shrink-0 items-center justify-center self-stretch overflow-hidden"
            :style="canvasWrapStyle"
        >
            <PyramidCanvas
                :stroke-width="effectivePyramidStrokeWidth"
                :stroke-color="pyramidStrokeColor"
                :show-controls="false"
                :offset-y-px="5 * size"
                :spin-when-hovered="spinWhenHovered"
                :is-hovered="logoHovered"
                :idle-rotation-y-deg="45"
                :spin-out-ms="compact ? 0 : 2200"
                :auto-spin-ramp-ms="autoSpinRampMs"
                :auto-spin-delay-ms="autoSpinDelayMs"
                class="h-full min-h-0 w-full"
            />
        </div>
        <div
            class="relative flex min-h-0 min-w-0 shrink flex-col justify-center self-stretch overflow-hidden"
            :style="textColumnStyle"
        >
            <span
                v-if="hasReservedWidth"
                class="pointer-events-none invisible flex flex-col select-none"
                aria-hidden="true"
            >
                <span
                    v-for="text in reserveLogoTexts"
                    :key="text"
                    class="inline-flex items-baseline leading-none"
                    :style="labelStyle"
                >
                    <StepText
                        :text="text"
                        pad-right
                        extra-height
                        compact-spaces
                        narrow-word-space
                        hover-group="prisma-logo"
                        :translate-px="150 * size"
                        :accent-from-index="7"
                        :base-char-class="baseCharClass"
                        :accent-char-class="accentCharClass"
                    />
                </span>
            </span>
            <span
                class="inline-flex items-baseline leading-none"
                :class="
                    hasReservedWidth
                        ? 'absolute top-1/2 left-0 -translate-y-1/2'
                        : ''
                "
                :style="labelStyle"
            >
                <StepText
                    :text="logoText"
                    :pad-right="!compact"
                    :extra-height="!compact"
                    compact-spaces
                    narrow-word-space
                    hover-group="prisma-logo"
                    :translate-px="150 * size"
                    :accent-from-index="7"
                    :base-char-class="baseCharClass"
                    :accent-char-class="accentCharClass"
                    :opacity-stagger-reveal="props.textRevealStagger"
                    :opacity-reveal-active="props.textRevealActive"
                />
            </span>
        </div>
    </div>
</template>
