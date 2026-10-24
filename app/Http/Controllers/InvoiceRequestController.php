<?php

namespace App\Http\Controllers;

use App\Models\CustomerStatement;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Services\Tickets\InvoiceService;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public page reached from the QR printed on the ticket. The token is unguessable
 * (≥128 bits), so it is the only credential needed to request or download the invoice.
 */
class InvoiceRequestController extends Controller
{
    /**
     * A ticket printed offline may not have reached the server yet: show a "try later"
     * state instead of a 404.
     */
    public function show(string $token): Response
    {
        abort_if(strlen($token) < 16, 404);
        $ticket = Ticket::query()->with('invoice', 'statement.invoice')->where('public_token', $token)->first();
        $invoice = $ticket?->invoice ?? $ticket?->statement?->invoice;

        return Inertia::render('public/InvoiceRequest', [
            'token' => $token,
            'business' => ['name' => AppSettings::get('business_name'), 'logoUrl' => AppSettings::logoUrl()],
            'ticket' => $ticket === null ? null : [
                'fullNumber' => $ticket->full_number,
                'issuedAt' => $ticket->issued_at->toIso8601String(),
                'total' => $ticket->total,
            ],
            'invoice' => $invoice ? ['fullNumber' => $invoice->full_number] : null,
        ]);
    }

    public function store(Request $request, string $token, InvoiceService $invoices): RedirectResponse
    {
        $ticket = $this->ticket($token);

        try {
            $invoices->issue($ticket, $this->customer($request));
        } catch (RuntimeException) {
            return back()->withErrors(['customer_name' => __('tpv.invoice_already_issued')]);
        }

        return to_route('invoice-request', $token);
    }

    public function pdf(string $token, InvoiceService $invoices): HttpResponse
    {
        $ticket = $this->ticket($token);

        return $this->download($ticket->invoice ?? $ticket->statement?->invoice, $invoices);
    }

    /**
     * The QR of a customer summary: one invoice for all its tickets. The form comes filled
     * with the customer's data when the staff has it.
     */
    public function showStatement(string $token): Response
    {
        $statement = $this->statement($token);

        return Inertia::render('public/InvoiceRequest', [
            'token' => $token,
            'business' => ['name' => AppSettings::get('business_name'), 'logoUrl' => AppSettings::logoUrl()],
            'ticket' => [
                'fullNumber' => $statement->label(),
                'issuedAt' => $statement->issued_at->toIso8601String(),
                'total' => $statement->total,
                'count' => $statement->tickets()->count(),
            ],
            'invoice' => $statement->invoice ? ['fullNumber' => $statement->invoice->full_number] : null,
            'urls' => [
                'store' => route('invoice-request.statement.store', $token),
                'pdf' => route('invoice-request.statement.pdf', $token),
            ],
            'customer' => [
                'name' => $statement->customer->name,
                'taxId' => $statement->customer->tax_id,
                'address' => $statement->customer->address,
                'email' => $statement->customer->email,
            ],
        ]);
    }

    public function storeStatement(Request $request, string $token, InvoiceService $invoices): RedirectResponse
    {
        $statement = $this->statement($token);

        try {
            $invoices->issueForStatement($statement, $this->customer($request));
        } catch (RuntimeException) {
            return back()->withErrors(['customer_name' => __('tpv.invoice_already_issued')]);
        }

        return to_route('invoice-request.statement', $token);
    }

    public function statementPdf(string $token, InvoiceService $invoices): HttpResponse
    {
        return $this->download($this->statement($token)->invoice, $invoices);
    }

    /**
     * @return array{customer_name: string, customer_tax_id: string, customer_address: string, customer_email: string|null}
     */
    private function customer(Request $request): array
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_tax_id' => ['required', 'string', 'min:8', 'max:30'],
            'address' => ['required', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:80'],
            'customer_email' => ['nullable', 'email', 'max:160'],
        ]);

        return [
            'customer_name' => $data['customer_name'],
            'customer_tax_id' => $data['customer_tax_id'],
            'customer_address' => trim(trim($data['address']).(filled($data['postal_code'] ?? null) || filled($data['city'] ?? null) ? ', '.trim(($data['postal_code'] ?? '').' '.($data['city'] ?? '')) : '')),
            'customer_email' => $data['customer_email'] ?? null,
        ];
    }

    private function download(?Invoice $invoice, InvoiceService $invoices): HttpResponse
    {
        abort_if($invoice === null, 404);

        $content = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
            ? (string) Storage::disk('local')->get($invoice->pdf_path)
            : $invoices->pdf($invoice);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->full_number.'.pdf"',
        ]);
    }

    private function ticket(string $token): Ticket
    {
        abort_if(strlen($token) < 16, 404);

        return Ticket::query()->with('invoice', 'statement.invoice')->where('public_token', $token)->firstOrFail();
    }

    private function statement(string $token): CustomerStatement
    {
        abort_if(strlen($token) < 16, 404);

        return CustomerStatement::query()->with('invoice', 'customer')->where('public_token', $token)->firstOrFail();
    }
}
