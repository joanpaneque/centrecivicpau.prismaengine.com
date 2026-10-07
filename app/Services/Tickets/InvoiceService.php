<?php

namespace App\Services\Tickets;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TicketSeries;
use App\Support\AppSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Full invoice (factura completa) issued on request for an existing simplified ticket.
 * Invoices have their own correlative series per year.
 */
class InvoiceService
{
    public function __construct(private readonly VerifactuService $verifactu) {}

    /**
     * @param  array{customer_name: string, customer_tax_id: string, customer_address: string, customer_email?: string|null}  $customer
     */
    public function issue(Ticket $ticket, array $customer, ?int $userId = null): Invoice
    {
        if ($ticket->invoice()->exists()) {
            throw new RuntimeException('invoice_already_issued');
        }

        $invoice = DB::transaction(function () use ($ticket, $customer) {
            $series = $this->series();
            $number = $series->last_number + 1;
            $series->forceFill(['last_number' => $number])->save();

            return Invoice::query()->create([
                'ticket_id' => $ticket->id,
                'ticket_series_id' => $series->id,
                'number' => $number,
                'full_number' => $series->format($number),
                'customer_name' => $customer['customer_name'],
                'customer_tax_id' => strtoupper(preg_replace('/[\s.-]/', '', $customer['customer_tax_id']) ?? ''),
                'customer_address' => $customer['customer_address'],
                'customer_email' => $customer['customer_email'] ?? null,
                'issued_at' => now(),
            ]);
        });

        $this->storePdf($invoice);
        AuditLog::record('invoice.issue', $invoice, ['ticket' => $ticket->full_number], userId: $userId);

        if ($invoice->customer_email) {
            $this->email($invoice);
        }

        return $invoice;
    }

    public function storePdf(Invoice $invoice): string
    {
        $path = 'invoices/'.$invoice->full_number.'.pdf';
        Storage::disk('local')->put($path, $this->pdf($invoice));
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    public function pdf(Invoice $invoice): string
    {
        $invoice->loadMissing('ticket.lines', 'ticket.payments');
        $ticket = $invoice->ticket;

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'ticket' => $ticket,
            'issuer' => $ticket->issuer,
            'logo' => $this->logoPath(),
            'qr' => $this->verifactu->qrUrl((string) ($ticket->issuer['tax_id'] ?? ''), $invoice->full_number, $invoice->issued_at->format('d-m-Y'), $ticket->total),
        ])->setPaper('a4')->output();
    }

    public function ticketPdf(Ticket $ticket): string
    {
        $ticket->loadMissing('lines', 'payments');

        return Pdf::loadView('pdf.ticket', [
            'ticket' => $ticket,
            'issuer' => $ticket->issuer,
            'logo' => $this->logoPath(),
        ])->setPaper([0, 0, 226.77, 841.89])->output();
    }

    public function email(Invoice $invoice): void
    {
        $pdf = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
            ? (string) Storage::disk('local')->get($invoice->pdf_path)
            : $this->pdf($invoice);

        try {
            $invoice->loadMissing('ticket');
            $body = __('tpv.invoice_mail_body', ['name' => $invoice->customer_name, 'number' => $invoice->full_number, 'ticket' => $invoice->ticket->full_number]);

            Mail::raw($body."\n\n".AppSettings::get('business_name'), function ($message) use ($invoice, $pdf) {
                $message->to((string) $invoice->customer_email)
                    ->subject(__('tpv.invoice_subject', ['number' => $invoice->full_number]))
                    ->attachData($pdf, $invoice->full_number.'.pdf', ['mime' => 'application/pdf']);
            });

            $invoice->forceFill(['emailed_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function series(): TicketSeries
    {
        $code = AppSettings::get('invoice_series_code', 'F').now()->format('Y');

        return TicketSeries::query()->where('code', $code)->lockForUpdate()->first()
            ?? TicketSeries::query()->create(['code' => $code, 'kind' => 'invoice', 'last_number' => 0]);
    }

    private function logoPath(): ?string
    {
        $path = AppSettings::get('logo_path');

        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        $default = public_path('images/logo-centre-civic.png');

        return is_file($default) ? $default : null;
    }
}
