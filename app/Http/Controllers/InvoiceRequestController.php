<?php

namespace App\Http\Controllers;

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
        $ticket = Ticket::query()->with('invoice')->where('public_token', $token)->first();

        return Inertia::render('public/InvoiceRequest', [
            'token' => $token,
            'business' => ['name' => AppSettings::get('business_name'), 'logoUrl' => AppSettings::logoUrl()],
            'ticket' => $ticket === null ? null : [
                'fullNumber' => $ticket->full_number,
                'issuedAt' => $ticket->issued_at->toIso8601String(),
                'total' => $ticket->total,
            ],
            'invoice' => $ticket?->invoice ? ['fullNumber' => $ticket->invoice->full_number] : null,
        ]);
    }

    public function store(Request $request, string $token, InvoiceService $invoices): RedirectResponse
    {
        $ticket = $this->ticket($token);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_tax_id' => ['required', 'string', 'min:8', 'max:30'],
            'address' => ['required', 'string', 'max:160'],
            'postal_code' => ['required', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:80'],
            'customer_email' => ['nullable', 'email', 'max:160'],
        ]);

        try {
            $invoices->issue($ticket, [
                'customer_name' => $data['customer_name'],
                'customer_tax_id' => $data['customer_tax_id'],
                'customer_address' => trim("{$data['address']}, {$data['postal_code']} {$data['city']}"),
                'customer_email' => $data['customer_email'] ?? null,
            ]);
        } catch (RuntimeException) {
            return back()->withErrors(['customer_name' => __('tpv.invoice_already_issued')]);
        }

        return to_route('invoice-request', $token);
    }

    public function pdf(string $token, InvoiceService $invoices): HttpResponse
    {
        $invoice = $this->ticket($token)->invoice;
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

        return Ticket::query()->with('invoice')->where('public_token', $token)->firstOrFail();
    }
}
