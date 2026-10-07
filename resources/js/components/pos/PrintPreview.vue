<script setup lang="ts">
import QRCode from 'qrcode';
import { ref, watchEffect } from 'vue';
import type { PrintDocument, PrintLine } from '@/pos/types';

const props = defineProps<{ document: PrintDocument; plain?: boolean }>();

const qrImages = ref<Record<string, string>>({});

watchEffect(async () => {
    const images: Record<string, string> = {};

    for (const line of props.document.lines) {
        if (line.type === 'qr' && !images[line.data]) {
            images[line.data] = await QRCode.toDataURL(line.data, { margin: 1, width: 180 });
        }
    }

    qrImages.value = images;
});

function textClass(line: Extract<PrintLine, { type: 'text' | 'row' }>): string[] {
    return [
        line.bold ? 'font-bold' : '',
        line.size === 2 ? 'text-[15px] leading-5' : '',
        'invert' in line && line.invert ? 'bg-black text-white px-1' : '',
    ];
}

function divider(line: Extract<PrintLine, { type: 'divider' }>): string {
    return (line.char ?? '=').repeat(props.document.width);
}
</script>

<template>
    <div
        class="mx-auto max-w-full overflow-hidden bg-white p-3 font-mono text-[11px] leading-4 text-black"
        :class="plain ? '' : 'rounded-sm shadow-md ring-1 ring-black/10'"
        :style="{ width: document.width <= 32 ? '58mm' : '80mm', '--chars': document.width }"
    >
        <template v-for="(line, index) in document.lines" :key="index">
            <div v-if="line.type === 'text'" :class="[textClass(line), line.align === 'center' ? 'text-center' : line.align === 'right' ? 'text-right' : '']" class="break-words whitespace-pre-wrap">
                {{ line.text }}
            </div>
            <div v-else-if="line.type === 'row'" :class="textClass(line)" class="flex justify-between gap-2">
                <span class="min-w-0 truncate whitespace-pre">{{ line.left }}</span>
                <span class="shrink-0 whitespace-pre">{{ line.right }}</span>
            </div>
            <div v-else-if="line.type === 'divider'" class="overflow-hidden whitespace-nowrap text-black/70">{{ divider(line) }}</div>
            <div v-else-if="line.type === 'qr'" class="my-2 flex flex-col items-center">
                <img v-if="qrImages[line.data]" :src="qrImages[line.data]" alt="QR" class="size-32" />
                <span v-if="line.caption" class="mt-1 text-center text-[10px]">{{ line.caption }}</span>
            </div>
            <div v-else-if="line.type === 'image'" class="mb-2 flex justify-center">
                <img :src="line.url" alt="" class="max-h-16 grayscale" />
            </div>
            <div v-else-if="line.type === 'feed'" :style="{ height: `${(line.lines ?? 1) * 0.75}rem` }" />
            <div v-else-if="line.type === 'cut'" class="-mx-3 mt-1 border-t border-dashed border-black/40 text-center text-[9px] text-black/40">✂</div>
        </template>
    </div>
</template>
