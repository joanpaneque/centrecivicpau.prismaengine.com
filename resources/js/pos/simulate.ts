import { t } from '@/i18n';
import { orderPrintJobs } from './orders';
import { ticketDocument, ticketPrinterId, widthFor } from './print';
import type { TicketView } from './print';
import { state } from './store';
import type { PosState } from './store';
import type { DeviceInfo, Destination, DiningTable, Me, Order, Printer, PrintJobPayload, Settings, Staff } from './types';

/**
 * Simulation from Gestió → Tiquets: lays out the order tickets and the ticket of a past
 * ticket with the very same code as the TPV, as seen from a device (its ticket printer,
 * the destinations and their printers). The context only lives in memory while laying
 * out: nothing is written to the TPV's local data of this browser.
 */
export type SimulationData = {
    context: {
        me: Me;
        device: DeviceInfo | null;
        settings: Settings;
        staff: Staff[];
        tables: DiningTable[];
        destinations: Destination[];
        printers: Printer[];
        operatorId: number | null;
    };
    order: Order | null;
    ticket: TicketView;
};

export type SimulatedJob = Omit<PrintJobPayload, 'uuid'> & { kind: 'order' | 'ticket' };

const KEYS = ['me', 'device', 'settings', 'staff', 'tables', 'destinations', 'printers', 'operatorId'] as const;

function byId<T extends { id: number }>(items: T[]): Record<number, T> {
    return Object.fromEntries(items.map((item) => [item.id, item]));
}

function withContext<T>(context: SimulationData['context'], fn: () => T): T {
    const saved = Object.fromEntries(KEYS.map((key) => [key, state[key]])) as Pick<PosState, (typeof KEYS)[number]>;

    Object.assign(state, {
        me: context.me,
        device: context.device,
        settings: context.settings,
        staff: byId(context.staff),
        tables: byId(context.tables),
        destinations: byId(context.destinations),
        printers: byId(context.printers),
        operatorId: context.operatorId ?? context.me.id,
    });

    try {
        return fn();
    } finally {
        Object.assign(state, saved);
    }
}

export function simulationJobs(data: SimulationData, what: { send: boolean; ticket: boolean; mark: boolean }): SimulatedJob[] {
    const jobs = withContext(data.context, () => {
        const out: SimulatedJob[] = [];

        if (what.send && data.order) {
            const lines = data.order.lines.filter((l) => !l.voided);
            out.push(...orderPrintJobs(data.order, lines, lines).map(({ kind, title, printerId, document }) => ({ kind: kind as 'order', title, printerId, document })));
        }

        if (what.ticket) {
            const printerId = ticketPrinterId();
            out.push({ kind: 'ticket', title: `${t('cashier.title')} · ${data.ticket.fullNumber}`, printerId, document: ticketDocument(data.ticket, widthFor(printerId)) });
        }

        return out;
    });

    if (!what.mark) {
        return jobs;
    }

    // One line on top so nobody in the kitchen takes it for a real order.
    return jobs.map((job) => ({
        ...job,
        title: `${t('admin.tickets.simulation.mark')} · ${job.title}`,
        document: { ...job.document, lines: [{ type: 'text', text: `*** ${t('admin.tickets.simulation.mark').toUpperCase()} ***`, align: 'center', bold: true }, ...job.document.lines] },
    }));
}
