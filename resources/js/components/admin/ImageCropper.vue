<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

export type CropRect = { x: number; y: number; width: number; height: number };

const props = withDefaults(defineProps<{ file: File; size?: number }>(), { size: 288 });
const emit = defineEmits<{ change: [crop: CropRect] }>();

const src = ref('');
const natural = ref({ width: 1, height: 1 });
const zoom = ref(1);
const offset = ref({ x: 0, y: 0 });

const baseScale = computed(() => Math.max(props.size / natural.value.width, props.size / natural.value.height));
const scale = computed(() => baseScale.value * zoom.value);

watch(
    () => props.file,
    (file) => {
        if (src.value) {
            URL.revokeObjectURL(src.value);
        }

        src.value = URL.createObjectURL(file);
        zoom.value = 1;
    },
    { immediate: true },
);

onBeforeUnmount(() => src.value && URL.revokeObjectURL(src.value));

function onLoad(event: Event): void {
    const img = event.target as HTMLImageElement;
    natural.value = { width: img.naturalWidth, height: img.naturalHeight };
    center();
}

function center(): void {
    offset.value = {
        x: (props.size - natural.value.width * scale.value) / 2,
        y: (props.size - natural.value.height * scale.value) / 2,
    };
    clampAndEmit();
}

function clampAndEmit(): void {
    const w = natural.value.width * scale.value;
    const h = natural.value.height * scale.value;
    offset.value = {
        x: Math.min(0, Math.max(props.size - w, offset.value.x)),
        y: Math.min(0, Math.max(props.size - h, offset.value.y)),
    };

    const side = Math.round(props.size / scale.value);
    emit('change', {
        x: Math.max(0, Math.round(-offset.value.x / scale.value)),
        y: Math.max(0, Math.round(-offset.value.y / scale.value)),
        width: Math.min(side, natural.value.width),
        height: Math.min(side, natural.value.height),
    });
}

watch(zoom, (value, old) => {
    const ratio = value / old;
    const c = props.size / 2;
    offset.value = { x: c - (c - offset.value.x) * ratio, y: c - (c - offset.value.y) * ratio };
    clampAndEmit();
});

let drag: { x: number; y: number; ox: number; oy: number } | null = null;

function down(event: PointerEvent): void {
    (event.target as HTMLElement).setPointerCapture(event.pointerId);
    drag = { x: event.clientX, y: event.clientY, ox: offset.value.x, oy: offset.value.y };
}

function move(event: PointerEvent): void {
    if (!drag) {
        return;
    }

    offset.value = { x: drag.ox + event.clientX - drag.x, y: drag.oy + event.clientY - drag.y };
    clampAndEmit();
}

function up(): void {
    drag = null;
}
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <div
            class="relative touch-none overflow-hidden rounded-lg bg-muted select-none"
            :style="{ width: `${size}px`, height: `${size}px` }"
            @pointerdown="down"
            @pointermove="move"
            @pointerup="up"
            @pointercancel="up"
        >
            <img
                :src="src"
                alt=""
                draggable="false"
                class="pointer-events-none absolute max-w-none origin-top-left"
                :style="{
                    left: `${offset.x}px`,
                    top: `${offset.y}px`,
                    width: `${natural.width * scale}px`,
                    height: `${natural.height * scale}px`,
                }"
                @load="onLoad"
            />
            <div class="pointer-events-none absolute inset-0 ring-2 ring-white/70 ring-inset" />
        </div>
        <input v-model.number="zoom" type="range" min="1" max="4" step="0.05" class="w-full" :style="{ maxWidth: `${size}px` }" />
    </div>
</template>
