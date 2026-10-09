import { xsrfToken } from '@/lib/http';
import type { PrintDocument } from './types';

function fileName(title: string): string {
    const slug = title.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-|-$/g, '');

    return `${slug || 'ticket'}.pdf`;
}

async function fetchTicketPdf(title: string, document: PrintDocument): Promise<Blob> {
    const response = await fetch('/tpv/api/tickets/pdf', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/pdf',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ title, document }),
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const blob = await response.blob();

    if (blob.type && !blob.type.includes('pdf') && !blob.type.includes('octet-stream')) {
        throw new Error('not_pdf');
    }

    return blob;
}

/**
 * Opens the ticket as a PDF in a new tab so the cashier can print it from Windows.
 */
export async function openTicketPdf(title: string, document: PrintDocument): Promise<void> {
    const blob = await fetchTicketPdf(title, document);
    const url = URL.createObjectURL(blob);
    const opened = window.open(url, '_blank', 'noopener');

    if (opened) {
        window.setTimeout(() => URL.revokeObjectURL(url), 60_000);

        return;
    }

    const link = window.document.createElement('a');
    link.href = url;
    link.download = fileName(title);
    link.rel = 'noopener';
    window.document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
