<script setup lang="ts">
import { Camera, Check, RotateCw, SunMedium, Upload, X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n';

const emit = defineEmits<{ done: [file: File]; cancel: [] }>();
const { t } = useI18n();

type Stage = 'choose' | 'camera' | 'edit';
const stage = ref<Stage>('choose');
const video = ref<HTMLVideoElement | null>(null);
const preview = ref<HTMLCanvasElement | null>(null);
const cameraError = ref('');
const enhance = ref(true);
const busy = ref(false);

let stream: MediaStream | null = null;
let source: HTMLCanvasElement | null = null;
let originalName = 'factura.jpg';

/** Crop rectangle in 0..1 coordinates of the (rotated) source image. */
const crop = ref({ x: 0.03, y: 0.03, w: 0.94, h: 0.94 });

async function startCamera(): Promise<void> {
    cameraError.value = '';

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 2560 }, height: { ideal: 1920 } },
            audio: false,
        });
        stage.value = 'camera';
        await nextTick();

        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play();
        }
    } catch {
        cameraError.value = t('clock.cameraError');
    }
}

function stopCamera(): void {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

onBeforeUnmount(stopCamera);

function capture(): void {
    const el = video.value;

    if (!el) {
        return;
    }

    const canvas = document.createElement('canvas');
    canvas.width = el.videoWidth;
    canvas.height = el.videoHeight;
    canvas.getContext('2d')?.drawImage(el, 0, 0);
    stopCamera();
    originalName = `factura-${Date.now()}.jpg`;
    loadSource(canvas);
}

async function pickFile(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    if (file.type === 'application/pdf') {
        emit('done', file);

        return;
    }

    originalName = file.name.replace(/\.\w+$/, '') + '.jpg';
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' } as ImageBitmapOptions);
    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 3000 / Math.max(bitmap.width, bitmap.height));
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    loadSource(canvas);
}

function loadSource(canvas: HTMLCanvasElement): void {
    source = canvas;
    crop.value = { x: 0.03, y: 0.03, w: 0.94, h: 0.94 };
    stage.value = 'edit';
    nextTick(renderPreview);
}

function rotate(): void {
    if (!source) {
        return;
    }

    const rotated = document.createElement('canvas');
    rotated.width = source.height;
    rotated.height = source.width;
    const ctx = rotated.getContext('2d');

    if (ctx) {
        ctx.translate(rotated.width, 0);
        ctx.rotate(Math.PI / 2);
        ctx.drawImage(source, 0, 0);
    }

    source = rotated;
    const c = crop.value;
    crop.value = { x: 1 - c.y - c.h, y: c.x, w: c.h, h: c.w };
    renderPreview();
}

/** Grayscale + auto-levels (2%–98% percentiles) + contrast curve: a cheap "scanner" look. */
function applyEnhance(ctx: CanvasRenderingContext2D, width: number, height: number): void {
    const image = ctx.getImageData(0, 0, width, height);
    const data = image.data;
    const histogram = new Uint32Array(256);

    for (let i = 0; i < data.length; i += 4) {
        const lum = Math.round(0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2]);
        data[i] = lum;
        histogram[lum]++;
    }

    const total = width * height;
    let low = 0;
    let high = 255;
    let acc = 0;

    for (let v = 0; v < 256; v++) {
        acc += histogram[v];

        if (acc > total * 0.02) {
            low = v;
            break;
        }
    }

    acc = 0;

    for (let v = 255; v >= 0; v--) {
        acc += histogram[v];

        if (acc > total * 0.02) {
            high = v;
            break;
        }
    }

    const range = Math.max(1, high - low);
    const lut = new Uint8ClampedArray(256);

    for (let v = 0; v < 256; v++) {
        const n = Math.min(1, Math.max(0, (v - low) / range));
        const curved = n < 0.5 ? 2 * n * n : 1 - 2 * (1 - n) * (1 - n);
        lut[v] = Math.round(255 * (0.35 * n + 0.65 * curved));
    }

    for (let i = 0; i < data.length; i += 4) {
        const value = lut[data[i]];
        data[i] = value;
        data[i + 1] = value;
        data[i + 2] = value;
    }

    ctx.putImageData(image, 0, 0);
}

function renderPreview(): void {
    const canvas = preview.value;

    if (!canvas || !source) {
        return;
    }

    const maxWidth = Math.min(canvas.parentElement?.clientWidth ?? 600, 900);
    const scale = Math.min(maxWidth / source.width, (window.innerHeight * 0.6) / source.height);
    canvas.width = Math.round(source.width * scale);
    canvas.height = Math.round(source.height * scale);
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return;
    }

    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);

    if (enhance.value) {
        applyEnhance(ctx, canvas.width, canvas.height);
    }
}

watch(enhance, renderPreview);

type Handle = 'tl' | 'tr' | 'bl' | 'br' | 'move';
let drag: { handle: Handle; startX: number; startY: number; start: { x: number; y: number; w: number; h: number } } | null = null;

function handleDown(event: PointerEvent, handle: Handle): void {
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    drag = { handle, startX: event.clientX, startY: event.clientY, start: { ...crop.value } };
}

function handleMove(event: PointerEvent): void {
    const canvas = preview.value;

    if (!drag || !canvas) {
        return;
    }

    const dx = (event.clientX - drag.startX) / canvas.clientWidth;
    const dy = (event.clientY - drag.startY) / canvas.clientHeight;
    const s = drag.start;
    const min = 0.08;
    let { x, y, w, h } = s;

    if (drag.handle === 'move') {
        x = Math.min(1 - w, Math.max(0, s.x + dx));
        y = Math.min(1 - h, Math.max(0, s.y + dy));
    } else {
        if (drag.handle === 'tl' || drag.handle === 'bl') {
            x = Math.min(s.x + s.w - min, Math.max(0, s.x + dx));
            w = s.x + s.w - x;
        } else {
            w = Math.min(1 - s.x, Math.max(min, s.w + dx));
        }

        if (drag.handle === 'tl' || drag.handle === 'tr') {
            y = Math.min(s.y + s.h - min, Math.max(0, s.y + dy));
            h = s.y + s.h - y;
        } else {
            h = Math.min(1 - s.y, Math.max(min, s.h + dy));
        }
    }

    crop.value = { x, y, w, h };
}

function handleUp(): void {
    drag = null;
}

async function finish(): Promise<void> {
    if (!source) {
        return;
    }

    busy.value = true;
    const c = crop.value;
    const sx = Math.round(c.x * source.width);
    const sy = Math.round(c.y * source.height);
    const sw = Math.round(c.w * source.width);
    const sh = Math.round(c.h * source.height);
    const out = document.createElement('canvas');
    out.width = sw;
    out.height = sh;
    const ctx = out.getContext('2d');

    if (ctx) {
        ctx.drawImage(source, sx, sy, sw, sh, 0, 0, sw, sh);

        if (enhance.value) {
            applyEnhance(ctx, sw, sh);
        }
    }

    const blob = await new Promise<Blob | null>((resolve) => out.toBlob(resolve, 'image/jpeg', 0.88));
    busy.value = false;

    if (blob) {
        emit('done', new File([blob], originalName, { type: 'image/jpeg' }));
    }
}

function restart(): void {
    source = null;
    stage.value = 'choose';
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="stage === 'choose'" class="grid gap-3 sm:grid-cols-2">
            <button type="button" class="flex flex-col items-center gap-2 rounded-xl border-2 border-dashed p-8 hover:bg-muted/40" @click="startCamera">
                <Camera class="size-10 text-primary" />
                <span class="font-medium">{{ t('admin.suppliers.takePhoto') }}</span>
            </button>
            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed p-8 hover:bg-muted/40">
                <Upload class="size-10 text-primary" />
                <span class="font-medium">{{ t('admin.suppliers.upload') }}</span>
                <span class="text-xs text-muted-foreground">JPG, PNG, PDF</span>
                <input type="file" accept="image/*,application/pdf" capture="environment" class="hidden" @change="pickFile" />
            </label>
            <p v-if="cameraError" class="text-sm text-destructive sm:col-span-2">{{ cameraError }}</p>
        </div>

        <div v-else-if="stage === 'camera'" class="space-y-3">
            <div class="relative overflow-hidden rounded-xl bg-black">
                <video ref="video" playsinline muted class="max-h-[60vh] w-full object-contain" />
                <div class="pointer-events-none absolute inset-6 rounded-lg border-2 border-white/60" />
            </div>
            <div class="flex justify-center gap-3">
                <Button variant="outline" @click="stopCamera(); restart()"><X class="size-4" /> {{ t('common.cancel') }}</Button>
                <Button size="lg" @click="capture"><Camera class="size-5" /> {{ t('admin.suppliers.takePhoto') }}</Button>
            </div>
        </div>

        <div v-else class="space-y-3">
            <div class="relative mx-auto w-fit touch-none select-none" @pointermove="handleMove" @pointerup="handleUp" @pointercancel="handleUp">
                <canvas ref="preview" class="block rounded-lg" />
                <div
                    class="absolute cursor-move border-2 border-primary shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]"
                    :style="{ left: `${crop.x * 100}%`, top: `${crop.y * 100}%`, width: `${crop.w * 100}%`, height: `${crop.h * 100}%` }"
                    @pointerdown.self="handleDown($event, 'move')"
                >
                    <span
                        v-for="corner in ['tl', 'tr', 'bl', 'br'] as const"
                        :key="corner"
                        class="absolute size-6 rounded-full border-2 border-white bg-primary"
                        :class="{
                            '-top-3 -left-3 cursor-nwse-resize': corner === 'tl',
                            '-top-3 -right-3 cursor-nesw-resize': corner === 'tr',
                            '-bottom-3 -left-3 cursor-nesw-resize': corner === 'bl',
                            '-right-3 -bottom-3 cursor-nwse-resize': corner === 'br',
                        }"
                        @pointerdown.stop="handleDown($event, corner)"
                    />
                </div>
            </div>
            <div class="flex flex-wrap justify-center gap-2">
                <Button variant="outline" @click="restart"><X class="size-4" /> {{ t('admin.suppliers.retake') }}</Button>
                <Button variant="outline" @click="rotate"><RotateCw class="size-4" /> {{ t('admin.suppliers.rotate') }}</Button>
                <Button :variant="enhance ? 'default' : 'outline'" @click="enhance = !enhance">
                    <SunMedium class="size-4" /> {{ t('admin.suppliers.enhance') }}
                </Button>
                <Button :disabled="busy" @click="finish"><Check class="size-4" /> {{ t('admin.suppliers.useImage') }}</Button>
            </div>
        </div>
    </div>
</template>
