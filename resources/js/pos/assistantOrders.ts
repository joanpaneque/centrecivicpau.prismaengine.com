import { reactive, watch } from 'vue';
import type { OrderPreview } from '@/assistant/api';
import { addToDraft, draftFor, draftFromProduct, sendDraft } from './orders';
import { activeOrderForTable, state } from './store';

const STORAGE_KEY = 'tpv-assistant-orders';

/**
 * Orders dictated to the assistant are added on this tablet to the table's unsent lines
 * (exactly as if tapped), once: what was applied, or sent, is remembered across reloads.
 */
export const assistantOrders = reactive<Record<string, 'added' | 'sent' | 'failed'>>(
    (() => {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}');
        } catch {
            return {};
        }
    })(),
);

watch(assistantOrders, (value) => {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch {
        // Only the "already added" memory is lost.
    }
}, { deep: true });

function tableDraft(tableId: number) {
    return draftFor(activeOrderForTable(tableId), tableId);
}

export async function applyAssistantOrder(preview: OrderPreview): Promise<void> {
    if (assistantOrders[preview.id] || !state.tables[preview.tableId]) {
        return;
    }

    const draft = tableDraft(preview.tableId);
    let added = 0;

    for (const line of preview.lines) {
        const product = state.products[line.productId];

        if (!product || product.deleted) {
            continue;
        }

        addToDraft(draft, draftFromProduct(product, line.modifiers.map((m) => ({ id: m.id, name: m.name, priceDelta: m.priceDelta })), line.note, null, line.quantity));
        added++;
    }

    assistantOrders[preview.id] = added ? 'added' : 'failed';

    if (added && preview.send) {
        await sendAssistantOrder(preview);
    }
}

/** Send everything waiting on that table to kitchen and bar. */
export async function sendAssistantOrder(preview: OrderPreview): Promise<void> {
    const draft = tableDraft(preview.tableId);

    if (draft.lines.length) {
        await sendDraft(draft);
    }

    assistantOrders[preview.id] = 'sent';
}

export function pendingLines(tableId: number): number {
    return tableDraft(tableId).lines.reduce((sum, line) => sum + line.quantity, 0);
}
