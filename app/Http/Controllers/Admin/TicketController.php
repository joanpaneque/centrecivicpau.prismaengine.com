<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Services\Tickets\InvoiceService;
use App\Support\AppSettings;
use App\Support\Translation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:60'],
            'method' => ['nullable', 'in:cash,card'],
        ]);

        $from = isset($filters['from']) ? now()->parse($filters['from'])->startOfDay() : now()->startOfDay()->subDays(6);
        $to = isset($filters['to']) ? now()->parse($filters['to'])->endOfDay() : now()->endOfDay();

        $query = Ticket::query()
            ->with(['payments', 'waiter:id,name', 'cashier:id,name', 'invoice:id,ticket_id,full_number'])
            ->whereBetween('issued_at', [$from, $to])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('full_number', 'like', "%{$s}%")->orWhere('table_label', 'like', "%{$s}%")))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->whereHas('payments', fn ($q) => $q->where('method', $m)));

        $totals = (clone $query)->toBase()->selectRaw('count(*) as count, coalesce(sum(total), 0) as total')->first();

        return Inertia::render('admin/Tickets', [
            'tickets' => $query->latest('issued_at')->paginate(40)->withQueryString()->through(fn (Ticket $t) => [
                'id' => $t->id,
                'fullNumber' => $t->full_number,
                'issuedAt' => $t->issued_at->toIso8601String(),
                'tableLabel' => $t->table_label,
                'waiter' => $t->waiter?->name,
                'cashier' => $t->cashier?->name,
                'total' => $t->total,
                'payments' => $t->payments->map(fn (Payment $p) => ['method' => $p->method, 'amount' => $p->amount])->values()->all(),
                'invoice' => $t->invoice?->full_number,
            ]),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()] + $filters,
            'totals' => ['count' => (int) ($totals->count ?? 0), 'total' => (int) ($totals->total ?? 0)],
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['lines', 'payments', 'waiter:id,name', 'cashier:id,name', 'invoice', 'series']);

        return Inertia::render('admin/TicketShow', [
            'ticket' => [
                'id' => $ticket->id,
                'fullNumber' => $ticket->full_number,
                'issuedAt' => $ticket->issued_at->toIso8601String(),
                'tableLabel' => $ticket->table_label,
                'waiter' => $ticket->waiter?->name,
                'cashier' => $ticket->cashier?->name,
                'subtotal' => $ticket->subtotal,
                'surchargeRate' => $ticket->surcharge_rate,
                'surchargeAmount' => $ticket->surcharge_amount,
                'discountTotal' => $ticket->discount_total,
                'total' => $ticket->total,
                'vatBreakdown' => $ticket->vat_breakdown,
                'issuer' => $ticket->issuer,
                'hash' => $ticket->hash,
                'previousHash' => $ticket->previous_hash,
                'verifactu' => $ticket->verifactu,
                'publicUrl' => route('invoice-request', $ticket->public_token),
                'lines' => $ticket->lines->map(fn (TicketLine $l) => [
                    'name' => Translation::pick($l->name),
                    'quantity' => $l->quantity,
                    'unitPrice' => $l->unit_price,
                    'vatRate' => $l->vat_rate,
                    'discountAmount' => $l->discount_amount,
                    'total' => $l->total,
                    'modifiers' => $l->modifiers ?? [],
                ])->values()->all(),
                'payments' => $ticket->payments->map(fn (Payment $p) => ['method' => $p->method, 'amount' => $p->amount, 'tendered' => $p->tendered, 'change' => $p->change])->values()->all(),
                'invoice' => $ticket->invoice ? [
                    'id' => $ticket->invoice->id,
                    'fullNumber' => $ticket->invoice->full_number,
                    'customerName' => $ticket->invoice->customer_name,
                    'customerTaxId' => $ticket->invoice->customer_tax_id,
                    'customerAddress' => $ticket->invoice->customer_address,
                    'customerEmail' => $ticket->invoice->customer_email,
                    'issuedAt' => $ticket->invoice->issued_at->toIso8601String(),
                    'emailedAt' => $ticket->invoice->emailed_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    public function pdf(Ticket $ticket, InvoiceService $invoices): HttpResponse
    {
        return response($invoices->ticketPdf($ticket), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$ticket->full_number.'.pdf"',
        ]);
    }

    public function issueInvoice(Request $request, Ticket $ticket, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_tax_id' => ['required', 'string', 'max:30'],
            'customer_address' => ['required', 'string', 'max:250'],
            'customer_email' => ['nullable', 'email', 'max:160'],
        ]);

        try {
            $invoices->issue($ticket, $data, $request->user()?->id);
            $this->toast(__('tpv.invoice_issued'));
        } catch (RuntimeException) {
            $this->toast(__('tpv.invoice_already_issued'), 'error');
        }

        return back();
    }

    public function invoices(Request $request): Response
    {
        $search = $request->string('search')->toString();

        return Inertia::render('admin/Invoices', [
            'invoices' => Invoice::query()
                ->with('ticket:id,full_number,total')
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('full_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")->orWhere('customer_tax_id', 'like', "%{$search}%")))
                ->latest('issued_at')
                ->paginate(40)
                ->withQueryString()
                ->through(fn (Invoice $i) => [
                    'id' => $i->id,
                    'ticketId' => $i->ticket_id,
                    'fullNumber' => $i->full_number,
                    'ticketNumber' => $i->ticket->full_number,
                    'customerName' => $i->customer_name,
                    'customerTaxId' => $i->customer_tax_id,
                    'customerEmail' => $i->customer_email,
                    'total' => $i->ticket->total,
                    'issuedAt' => $i->issued_at->toIso8601String(),
                    'emailedAt' => $i->emailed_at?->toIso8601String(),
                ]),
            'search' => $search,
        ]);
    }

    public function invoicePdf(Invoice $invoice, InvoiceService $invoices): HttpResponse
    {
        $content = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
            ? (string) Storage::disk('local')->get($invoice->pdf_path)
            : $invoices->pdf($invoice);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->full_number.'.pdf"',
        ]);
    }

    public function resendInvoice(Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        if ($invoice->customer_email) {
            $invoices->email($invoice);
        }

        $this->toast(__('tpv.saved'));

        return back();
    }

    public function cashSessions(): Response
    {
        return Inertia::render('admin/CashSessions', [
            'sessions' => CashSession::query()
                ->with(['device:id,name', 'opener:id,name', 'closer:id,name'])
                ->latest('opened_at')
                ->paginate(30)
                ->through(fn (CashSession $s) => [
                    'id' => $s->id,
                    'device' => $s->device?->name,
                    'openedAt' => $s->opened_at->toIso8601String(),
                    'openedBy' => $s->opener?->name,
                    'closedAt' => $s->closed_at?->toIso8601String(),
                    'closedBy' => $s->closer?->name,
                    'zNumber' => $s->z_number,
                    'openingFloat' => $s->opening_float,
                    'expectedCash' => $s->expected_cash,
                    'countedCash' => $s->counted_cash,
                    'difference' => $s->difference,
                    'total' => (int) ($s->summary['total'] ?? 0),
                    'tickets' => (int) ($s->summary['tickets'] ?? 0),
                    'summary' => $s->summary,
                ]),
        ]);
    }

    public function zPdf(CashSession $session): HttpResponse
    {
        $session->load(['device', 'opener', 'closer']);

        $pdf = Pdf::loadView('pdf.z-report', ['session' => $session, 'issuer' => AppSettings::issuer()])->setPaper('a4')->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Z-'.($session->z_number ?? $session->id).'.pdf"',
        ]);
    }
}
