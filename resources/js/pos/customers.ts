import { t, tr } from '@/i18n';
import { api } from '@/lib/http';
import { formatMoney, formatRate } from './money';
import { businessHeader, deliveryNoteDocument } from './print';
import { enqueue } from './sync';
import type { Order, PrintDocument, PrintLine, T9n } from './types';

/**
 * Regular customers with a code of their own (online): their orders and tickets are assigned
 * to them and, at the end, they get a summary ticket of everything with a QR that asks for
 * one full invoice of all of it.
 */
export type Customer = { id: number; code: string; name: string; taxId: string | null; address: string | null; email: string | null; phone: string | null };
export type CustomerListItem = Customer & { pendingCount: number; pendingTotal: number };
export type CustomerTicket = { uuid: string; fullNumber: string; issuedAt: string; tableLabel: string | null; total: number; customerCode?: string | null };
export type StatementSummary = { id: number; label: string; issuedAt: string; total: number; invoice: string | null };
export type DeliveryNoteItem = {
    uuid: string;
    issuedAt: string;
    tableLabel: string | null;
    total: number;
    surchargeRate: number;
    surchargeAmount: number;
    vatBreakdown: { rate: number; base: number; vat: number; total: number }[];
    lines: { name: T9n; quantity: number; unitPrice: number; vatRate: number; total: number }[];
    /** An order to charge it is already open. */
    collecting: boolean;
};
export type CustomerDetail = { customer: Customer; pending: CustomerTicket[]; statements: StatementSummary[]; notes: DeliveryNoteItem[] };
export type CustomerInput = { code: string; name: string; taxId: string | null; address: string | null; email: string | null; phone: string | null };

export type Statement = {
    id: number;
    label: string;
    issuedAt: string;
    total: number;
    customer: { code: string; name: string; taxId: string | null };
    invoiceUrl: string;
    invoice: string | null;
    vatBreakdown: { rate: number; base: number; vat: number; total: number }[];
    tickets: { fullNumber: string; issuedAt: string; tableLabel: string | null; total: number; lines: { name: T9n; quantity: number; unitPrice: number; total: number }[] }[];
};

export async function searchCustomers(query: string): Promise<CustomerListItem[]> {
    return (await api<{ customers: CustomerListItem[] }>('GET', `/tpv/api/customers?q=${encodeURIComponent(query)}`)).customers;
}

export async function createCustomer(data: CustomerInput): Promise<Customer> {
    return (await api<{ customer: Customer }>('POST', '/tpv/api/customers', data)).customer;
}

export async function updateCustomer(id: number, data: CustomerInput): Promise<Customer> {
    return (await api<{ customer: Customer }>('PATCH', `/tpv/api/customers/${id}`, data)).customer;
}

export async function customerDetail(id: number): Promise<CustomerDetail> {
    return api<CustomerDetail>('GET', `/tpv/api/customers/${id}`);
}

/** Recent paid tickets still free to go to a customer. */
export async function searchTickets(query: string): Promise<CustomerTicket[]> {
    return (await api<{ tickets: CustomerTicket[] }>('GET', `/tpv/api/customer-tickets?q=${encodeURIComponent(query)}`)).tickets;
}

export async function attachTicket(customerId: number, ticketUuid: string): Promise<CustomerDetail> {
    return api<CustomerDetail>('POST', `/tpv/api/customers/${customerId}/tickets`, { ticketUuid });
}

export async function detachTicket(customerId: number, ticketUuid: string): Promise<CustomerDetail> {
    return api<CustomerDetail>('DELETE', `/tpv/api/customers/${customerId}/tickets/${ticketUuid}`);
}

export async function createStatement(customerId: number, ticketUuids: string[]): Promise<Statement> {
    return (await api<{ statement: Statement }>('POST', `/tpv/api/customers/${customerId}/statements`, { ticketUuids }, 30000)).statement;
}

/** The chosen delivery notes become an order of the customer, to charge like any other. */
export async function collectNotes(customerId: number, noteUuids: string[]): Promise<Order> {
    return (await api<{ order: Order }>('POST', `/tpv/api/customers/${customerId}/notes/collect`, { noteUuids }, 30000)).order;
}

/** A pending delivery note printed again (both copies). */
export function noteDocument(note: DeliveryNoteItem, customer: Pick<Customer, 'code' | 'name'>, width: number): PrintDocument {
    return deliveryNoteDocument(
        {
            fullNumber: null,
            issuedAt: note.issuedAt,
            tableLabel: note.tableLabel,
            waiter: '',
            lines: note.lines.map((line) => ({ name: tr(line.name), quantity: line.quantity, unitPrice: line.unitPrice, total: line.total, vatRate: line.vatRate })),
            surchargeRate: note.surchargeRate,
            surchargeAmount: note.surchargeAmount,
            total: note.total,
            vatBreakdown: note.vatBreakdown,
            payments: [],
        },
        customer,
        width,
    );
}

export async function fetchStatement(id: number): Promise<Statement> {
    return (await api<{ statement: Statement }>('GET', `/tpv/api/statements/${id}`)).statement;
}

/** The order (and its tickets, once paid) goes to this customer, or to none. Works offline. */
export async function assignCustomer(order: Order, customer: Pick<Customer, 'id' | 'code' | 'name'> | null): Promise<void> {
    await enqueue('order.customer', {
        orderUuid: order.uuid,
        customerId: customer?.id ?? null,
        customer: customer ? { id: customer.id, code: customer.code, name: customer.name } : null,
    });
}

function time(iso: string): string {
    const date = new Date(iso);

    return `${date.toLocaleDateString('es-ES')} ${date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })}`;
}

/**
 * The summary ticket: the customer, every ticket with its lines, the VAT of everything and
 * the QR to ask for one invoice of it all. Not valid as an invoice.
 */
export function statementDocument(statement: Statement, width: number): PrintDocument {
    const out: PrintLine[] = [];
    businessHeader(out);
    out.push({ type: 'divider' });
    out.push({ type: 'text', text: t('customers.statementTitle').toUpperCase(), align: 'center', bold: true, size: 2 });
    out.push({ type: 'text', text: `${statement.label} · ${time(statement.issuedAt)}`, align: 'center' });
    out.push({ type: 'divider' });
    out.push({ type: 'text', text: `${statement.customer.code} · ${statement.customer.name}`, bold: true });

    if (statement.customer.taxId) {
        out.push({ type: 'text', text: `NIF ${statement.customer.taxId}` });
    }

    for (const ticket of statement.tickets) {
        out.push({ type: 'divider', char: '-' });
        out.push({ type: 'row', left: `${ticket.fullNumber}${ticket.tableLabel ? ` · ${ticket.tableLabel}` : ''}`, right: time(ticket.issuedAt), bold: true });

        for (const line of ticket.lines) {
            const qty = Number.isInteger(line.quantity) ? String(line.quantity) : line.quantity.toFixed(2);
            out.push({ type: 'row', left: `${qty} ${tr(line.name)}`.slice(0, width - 10), right: formatMoney(line.total, false) });
        }

        out.push({ type: 'row', left: t('customers.ticketTotal'), right: formatMoney(ticket.total, false) });
    }

    out.push({ type: 'divider' });
    out.push({ type: 'row', left: t('customers.ticketsCount', { count: statement.tickets.length }), right: '' });
    out.push({ type: 'row', left: t('common.total').toUpperCase(), right: formatMoney(statement.total), bold: true, size: 2 });
    out.push({ type: 'row', left: `${t('common.vat')}  ${t('cashier.base')}`, right: t('cashier.quota') });

    for (const row of statement.vatBreakdown) {
        out.push({ type: 'row', left: `${formatRate(row.rate).padEnd(5)} ${formatMoney(row.base, false)}`, right: formatMoney(row.vat, false) });
    }

    out.push({ type: 'feed' });
    out.push({ type: 'text', text: '*** NO VÀLID COM A FACTURA / NO VÁLIDO COMO FACTURA ***', align: 'center' });

    if (statement.invoice) {
        out.push({ type: 'text', text: t('customers.alreadyInvoiced', { number: statement.invoice }), align: 'center', bold: true });
    } else {
        out.push({ type: 'qr', data: statement.invoiceUrl, caption: t('customers.invoiceQr') });
    }

    out.push({ type: 'feed', lines: 2 }, { type: 'cut' });

    return { width, lines: out };
}
