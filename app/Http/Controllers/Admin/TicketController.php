<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceType;
use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Services\Devices\DeviceManager;
use App\Services\Printing\PrintService;
use App\Services\Sync\SnapshotBuilder;
use App\Services\Tickets\InvoiceService;
use App\Support\AppSettings;
use App\Support\Translation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TicketController extends Controller
{
    public function index(Request $request, DeviceManager $devices): Response
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
                'payments' => $t->payments->map(fn (Payment $p) => ['id' => $p->id, 'method' => $p->method, 'amount' => $p->amount])->values()->all(),
                'invoice' => $t->invoice?->full_number,
                'hasOrder' => $t->order_id !== null,
            ]),
            'devices' => $this->simulationDevices(),
            'currentDeviceId' => $devices->fromRequest($request)?->id,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()] + $filters,
            'totals' => ['count' => (int) ($totals->count ?? 0), 'total' => (int) ($totals->total ?? 0)],
        ]);
    }

    public function show(Request $request, Ticket $ticket, DeviceManager $devices): Response
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
                'payments' => $ticket->payments->map(fn (Payment $p) => ['id' => $p->id, 'method' => $p->method, 'amount' => $p->amount, 'tendered' => $p->tendered, 'change' => $p->change])->values()->all(),
                'hasOrder' => $ticket->order_id !== null,
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
            'devices' => $this->simulationDevices(),
            'currentDeviceId' => $devices->fromRequest($request)?->id,
        ]);
    }

    /**
     * Correct how a ticket was paid (cash or card). The amounts do not change, and the
     * payment method is not part of the Verifactu fingerprint, so the chain stays valid.
     */
    public function updatePayments(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.id' => ['required', 'integer', Rule::exists('payments', 'id')->where('ticket_id', $ticket->id)],
            'payments.*.method' => ['required', Rule::in(['cash', 'card'])],
        ]);

        $changes = [];

        foreach ($data['payments'] as $row) {
            $payment = $ticket->payments()->whereKey($row['id'])->firstOrFail();

            if ($payment->method === $row['method']) {
                continue;
            }

            $changes[] = ['payment' => $payment->id, 'amount' => $payment->amount, 'from' => $payment->method, 'to' => $row['method']];
            // The cash handed over and the change only make sense for cash that was really counted.
            $payment->forceFill(['method' => $row['method'], 'tendered' => null, 'change' => null])->save();
        }

        if ($changes === []) {
            return back();
        }

        AuditLog::record('ticket.payment_method', $ticket, ['changes' => $changes]);
        TpvChanged::notify(['cashier']);

        $closed = $ticket->cash_session_id !== null && CashSession::query()->whereKey($ticket->cash_session_id)->whereNotNull('closed_at')->exists();
        $this->toast($closed ? __('tpv.payment_method_changed_closed') : __('tpv.payment_method_changed'), $closed ? 'warning' : 'success');

        return back();
    }

    /**
     * Everything the browser needs to lay out the order and the ticket exactly as the TPV
     * does (same code), as seen from the chosen device: its ticket printer, the
     * destinations and their printers, the business header.
     */
    public function simulation(Request $request, Ticket $ticket, SnapshotBuilder $snapshots): JsonResponse
    {
        $data = $request->validate(['device' => ['nullable', 'integer', 'exists:devices,id']]);
        $device = isset($data['device']) ? Device::query()->find($request->integer('device')) : null;
        $user = $request->user();
        abort_if($user === null, 403);

        $snapshot = $snapshots->build($user, $device);
        $ticket->load(['lines', 'payments', 'waiter:id,name', 'order.lines', 'order.table']);
        $paid = (int) $ticket->payments->sum('amount');

        return response()->json([
            'context' => [
                'me' => $snapshot['me'],
                'device' => $snapshot['device'],
                'settings' => $snapshot['settings'],
                'staff' => $snapshot['staff'],
                'tables' => $snapshot['tables'],
                'destinations' => $snapshot['destinations'],
                'printers' => $snapshot['printers'],
                'operatorId' => $ticket->waiter_id,
            ],
            'order' => $ticket->order ? $snapshots->order($ticket->order) : null,
            'ticket' => [
                'fullNumber' => $ticket->full_number,
                'issuedAt' => $ticket->issued_at->toIso8601String(),
                'tableLabel' => $ticket->table_label,
                'waiter' => $ticket->waiter->name ?? '',
                'lines' => $ticket->lines->map(fn (TicketLine $l) => [
                    'name' => Translation::pick($l->name),
                    'quantity' => $l->quantity,
                    'unitPrice' => $l->unit_price,
                    'total' => $l->total,
                    'vatRate' => $l->vat_rate,
                    'discount' => $l->discount_amount,
                    'details' => collect($l->modifiers ?? [])->map(fn (array $m) => '+ '.Translation::pick(is_array($m['name'] ?? null) ? $m['name'] : null))->values()->all(),
                ])->values()->all(),
                'surchargeRate' => $ticket->surcharge_rate,
                'surchargeAmount' => $ticket->surcharge_amount,
                'total' => $ticket->total,
                'vatBreakdown' => $ticket->vat_breakdown,
                'payments' => $ticket->payments->map(fn (Payment $p) => ['method' => $p->method, 'amount' => $p->amount, 'tendered' => $p->tendered, 'change' => $p->change])->values()->all(),
                'invoiceUrl' => route('invoice-request', $ticket->public_token),
                'verifactuUrl' => $ticket->verifactu['qr_url'] ?? null,
                'tip' => max(0, $paid - $ticket->total) ?: null,
            ],
        ]);
    }

    /**
     * Print what the simulation laid out: the same jobs the TPV would have sent, to the same
     * printers (through the print program, the network or the cashier browser).
     */
    public function simulate(Request $request, Ticket $ticket, PrintService $print): JsonResponse
    {
        $data = $request->validate([
            'device' => ['nullable', 'integer', 'exists:devices,id'],
            'jobs' => ['required', 'array', 'min:1', 'max:20'],
            'jobs.*.kind' => ['required', Rule::in(['order', 'ticket'])],
            'jobs.*.title' => ['required', 'string', 'max:120'],
            'jobs.*.printerId' => ['nullable', 'integer', 'exists:printers,id'],
            'jobs.*.document' => ['required', 'array'],
            'jobs.*.document.lines' => ['required', 'array', 'max:500'],
        ]);

        $names = [];

        foreach ($data['jobs'] as $job) {
            $stored = $print->store((string) Str::uuid(), $job['kind'], $job['title'], $job['document'], $job['printerId'] ?? null, $request->user()?->id, $data['device'] ?? null);
            $names[] = $stored->printer_name;
        }

        AuditLog::record('ticket.simulate', $ticket, ['jobs' => count($data['jobs']), 'device' => $data['device'] ?? null]);
        TpvChanged::notify(['prints']);

        return response()->json(['printers' => array_values(array_unique(array_filter($names)))]);
    }

    /**
     * @return list<array{id: int, name: string, type: string}>
     */
    private function simulationDevices(): array
    {
        $devices = [];

        foreach (Device::query()->where('active', true)->whereIn('type', [DeviceType::Cashier, DeviceType::Tablet])->orderBy('name')->get() as $device) {
            $devices[] = ['id' => $device->id, 'name' => $device->name, 'type' => $device->type->value];
        }

        return $devices;
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
                ->with('ticket:id,full_number,total', 'statement.tickets:id,customer_statement_id,full_number,total')
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('full_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")->orWhere('customer_tax_id', 'like', "%{$search}%")))
                ->latest('issued_at')
                ->paginate(40)
                ->withQueryString()
                ->through(fn (Invoice $i) => [
                    'id' => $i->id,
                    'ticketId' => $i->ticket_id,
                    'fullNumber' => $i->full_number,
                    // A customer summary invoice covers several tickets: its summary number.
                    'ticketNumber' => $i->ticket->full_number ?? $i->statement?->label().' ('.$i->statement?->tickets->count().')',
                    'customerName' => $i->customer_name,
                    'customerTaxId' => $i->customer_tax_id,
                    'customerEmail' => $i->customer_email,
                    'total' => $i->ticket->total ?? (int) $i->statement?->tickets->sum('total'),
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
