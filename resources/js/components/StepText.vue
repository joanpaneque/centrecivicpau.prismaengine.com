<script setup>
import { computed } from 'vue';

import {
    OPACITY_STAGGER_CHAR_MS,
    OPACITY_STAGGER_STEP_MS,
    REVEAL_BLUR_PX,
    REVEAL_OFFSET_X_PX,
} from '@/utils/splashTextReveal.js';

/** Duración del transform: primera letra más rápida, última más lenta. */
const MIN_MS = 200;
const MAX_MS = 720;
/** Retardo acumulado: cada letra empieza un poco después que la anterior (efecto cascada). */
const DELAY_MIN_MS = 0;
const DELAY_MAX_MS = 140;
/** Px extra bajo la línea base cuando `extraHeight` es true (logo). */
const EXTRA_HEIGHT_PX = 20;
/** Altura de línea fija en rem (evita `lh`, que difiere entre SSR y cliente con web fonts). */
const LINE_HEIGHT_REM = 1.5;

const props = defineProps({
    text: {
        type: String,
        default: '',
    },
    /** Desplazamiento vertical del efecto (px). */
    translatePx: {
        type: Number,
        default: 20,
    },
    /**
     * Si se define, los caracteres con índice &lt; accentFromIndex usan `baseCharClass`
     * y el resto `accentCharClass` (p. ej. "Prisma" vs " Engine" en índice 7).
     */
    accentFromIndex: {
        type: Number,
        default: null,
    },
    baseCharClass: {
        type: String,
        default: '',
    },
    accentCharClass: {
        type: String,
        default: '',
    },
    /**
     * Si es true, la altura es `calc(1.5rem + 20px)`; si false, solo `1.5rem`.
     */
    extraHeight: {
        type: Boolean,
        default: false,
    },
    /** Si es true, añade 10px de padding derecho (p. ej. logo y `overflow-hidden`). */
    padRight: {
        type: Boolean,
        default: false,
    },
    /**
     * Si es true, cada carácter pasa de opacidad 0 a 1 con retardo escalonado
     * (requiere `opacityRevealActive` true para mostrar).
     */
    opacityStaggerReveal: {
        type: Boolean,
        default: false,
    },
    /** Activa la transición a opacidad 1 en cada letra (p. ej. tras el canvas del splash). */
    opacityRevealActive: {
        type: Boolean,
        default: false,
    },
    /** Px extra de margen a la izquierda de la primera letra accent (p. ej. hueco tras "Prisma "). */
    gapBeforeAccentPx: {
        type: Number,
        default: 0,
    },
    /**
     * Si es true, un solo ancho de espacio por cada ` ` (logo); si no, 2× `\u00A0` (nav, etc.).
     */
    compactSpaces: {
        type: Boolean,
        default: false,
    },
    /**
     * Solo logo: espacio entre palabras un poco más estrecho (U+2009 thin space).
     * Suele ir con `compactSpaces`.
     */
    narrowWordSpace: {
        type: Boolean,
        default: false,
    },
    /**
     * Grupo Tailwind named (p. ej. "services" → group-hover/services) para mantener
     * el hover cuando el puntero está fuera del elemento pero dentro del ancestro group.
     */
    hoverGroup: {
        type: String,
        default: null,
    },
    /**
     * Hover controlat per prop (sense group-hover).
     * Cal passar `controlled` + `hoverActive` des del pare.
     */
    controlled: {
        type: Boolean,
        default: false,
    },
    hoverActive: {
        type: Boolean,
        default: false,
    },
    /** Sin animación hover ni fila duplicada (p. ej. logos compactos en menús). */
    static: {
        type: Boolean,
        default: false,
    },
});

const chars = computed(() => Array.from(props.text));

function spaceGlyph() {
    if (props.narrowWordSpace) {
        return '\u2009';
    }

    if (props.compactSpaces) {
        return '\u00A0';
    }

    return '\u00A0\u00A0';
}

/** Igual que cada espacio renderizado (medida fantasma alineada al dibujo). */
const textForMeasure = computed(() => props.text.replace(/ /g, spaceGlyph()));

/** Primera letra (0 %) → MIN_MS; última (100 %) → MAX_MS; el resto interpolado. */
function transitionDurationMs(index, total) {
    if (total <= 1) {
        return MIN_MS;
    }

    const t = index / (total - 1);

    return Math.round(MIN_MS + t * (MAX_MS - MIN_MS));
}

/** Primera letra sin delay; la última espera hasta DELAY_MAX_MS (inicio escalonado). */
function transitionDelayMs(index, total) {
    if (total <= 1) {
        return DELAY_MIN_MS;
    }

    const t = index / (total - 1);

    return Math.round(DELAY_MIN_MS + t * (DELAY_MAX_MS - DELAY_MIN_MS));
}

function accentGapMargin(index) {
    const n = props.accentFromIndex;

    if (props.gapBeforeAccentPx <= 0 || n == null || index !== n) {
        return {};
    }

    return { marginLeft: `${props.gapBeforeAccentPx}px` };
}

function charTransitionStyle(index, total) {
    return {
        transitionDuration: `${transitionDurationMs(index, total)}ms`,
        transitionDelay: `${transitionDelayMs(index, total)}ms`,
        ...accentGapMargin(index),
    };
}

/** Transform (hover) + opcional fade por letras (splash: X + blur + opacidad). */
function charMotionStyle(index, total, row) {
    if (!props.opacityStaggerReveal) {
        return charTransitionStyle(index, total);
    }

    const delay = index * OPACITY_STAGGER_STEP_MS;
    const dur = OPACITY_STAGGER_CHAR_MS;
    const t = `${dur}ms cubic-bezier(0.22, 1, 0.36, 1) ${delay}ms`;
    const active = props.opacityRevealActive;
    const x0 = -REVEAL_OFFSET_X_PX;
    const yUp = '0';
    const yDown = 'var(--st-step)';
    const from =
        row === 'up'
            ? `translateX(${x0}px) translateY(${yUp})`
            : `translateX(${x0}px) translateY(${yDown})`;
    const to =
        row === 'up'
            ? `translateX(0) translateY(${yUp})`
            : `translateX(0) translateY(${yDown})`;

    return {
        opacity: active ? 1 : 0,
        transform: active ? to : from,
        filter: active ? 'blur(0)' : `blur(${REVEAL_BLUR_PX}px)`,
        transition: `opacity ${t}, transform ${t}, filter ${t}`,
        ...accentGapMargin(index),
    };
}

/** El espacio entre `inline-block` colapsa; el glifo depende de `narrowWordSpace` / `compactSpaces`. */
function displayChar(char) {
    if (char === ' ') {
        return spaceGlyph();
    }

    return char;
}

function charClass(index) {
    const n = props.accentFromIndex;

    if (n == null || n < 0) {
        return '';
    }

    return index < n ? props.baseCharClass : props.accentCharClass;
}

function usesFullSlide() {
    return props.translatePx === 20 && !props.extraHeight;
}

function hoverUpRowClass() {
    if (props.static || props.opacityStaggerReveal) {
        return '';
    }

    if (props.controlled) {
        if (props.hoverActive) {
            return usesFullSlide()
                ? '-translate-y-full transition-transform'
                : '-translate-y-[var(--st-step)] transition-transform';
        }

        return 'translate-y-0 transition-transform';
    }

    if (props.hoverGroup === 'services') {
        return usesFullSlide()
            ? 'translate-y-0 transition-transform group-hover/services:-translate-y-full'
            : 'translate-y-0 transition-transform group-hover/services:-translate-y-[var(--st-step)]';
    }

    if (props.hoverGroup === 'prisma-logo') {
        return usesFullSlide()
            ? 'translate-y-0 transition-transform group-hover/prisma-logo:-translate-y-full'
            : 'translate-y-0 transition-transform group-hover/prisma-logo:-translate-y-[var(--st-step)]';
    }

    return usesFullSlide()
        ? 'translate-y-0 transition-transform group-hover:-translate-y-full'
        : 'translate-y-0 transition-transform group-hover:-translate-y-[var(--st-step)]';
}

function hoverDownRowClass() {
    if (props.static || props.opacityStaggerReveal) {
        return '';
    }

    if (props.controlled) {
        if (props.hoverActive) {
            return 'translate-y-0 transition-transform';
        }

        return usesFullSlide()
            ? 'translate-y-full transition-transform'
            : 'translate-y-[var(--st-step)] transition-transform';
    }

    if (props.hoverGroup === 'services') {
        return usesFullSlide()
            ? 'translate-y-full transition-transform group-hover/services:translate-y-0'
            : 'translate-y-[var(--st-step)] transition-transform group-hover/services:translate-y-0';
    }

    if (props.hoverGroup === 'prisma-logo') {
        return usesFullSlide()
            ? 'translate-y-full transition-transform group-hover/prisma-logo:translate-y-0'
            : 'translate-y-[var(--st-step)] transition-transform group-hover/prisma-logo:translate-y-0';
    }

    return usesFullSlide()
        ? 'translate-y-full transition-transform group-hover:translate-y-0'
        : 'translate-y-[var(--st-step)] transition-transform group-hover:translate-y-0';
}
</script>

<template>
    <span
        class="cursor-inherit inline-grid w-max grid-cols-1 grid-rows-1 place-items-center overflow-hidden select-none"
        :class="{ 'pr-[10px]': padRight }"
        suppressHydrationWarning
        :style="{
            '--st-step': `${translatePx}px`,
            height: props.static
                ? `${LINE_HEIGHT_REM}rem`
                : `calc(${LINE_HEIGHT_REM}rem + ${extraHeight ? EXTRA_HEIGHT_PX : 0}px)`,
        }"
    >
        <span
            class="invisible col-start-1 row-start-1 whitespace-nowrap"
            suppressHydrationWarning
            >{{ textForMeasure }}</span
        >
        <span
            class="col-start-1 row-start-1 inline-flex"
            suppressHydrationWarning
        >
            <span
                v-for="(char, i) in chars"
                :key="`up-${i}`"
                class="inline-block ease-out"
                suppressHydrationWarning
                :class="[charClass(i), hoverUpRowClass()]"
                :style="charMotionStyle(i, chars.length, 'up')"
                >{{ displayChar(char) }}</span
            >
        </span>
        <span
            v-if="!static"
            class="col-start-1 row-start-1 inline-flex"
            suppressHydrationWarning
        >
            <span
                v-for="(char, i) in chars"
                :key="`down-${i}`"
                class="inline-block ease-out"
                suppressHydrationWarning
                :class="[charClass(i), hoverDownRowClass()]"
                :style="charMotionStyle(i, chars.length, 'down')"
                >{{ displayChar(char) }}</span
            >
        </span>
    </span>
</template>
