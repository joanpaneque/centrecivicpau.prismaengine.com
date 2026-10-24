<?php

namespace App\Http\Controllers\Tpv;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\Ticket;
use App\Services\Customers\StatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Customers from the TPV (online): find or create them, see their pending tickets, add or
 * take out tickets already paid, and make the summary ticket of everything.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly StatementService $statements) {}

    public function index(Request $request): JsonResponse
    {
        $this->staff($request);
        $search = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->withCount('pendingTickets as pending_count')
            ->withSum('pendingTickets as pending_total', 'total')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('lower(code) like ?', ['%'.mb_strtolower($search).'%'])->orWhereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])))
            ->orderBy('code')
            ->limit(60)
            ->get();

        return response()->json(['customers' => $customers->map(fn (Customer $c) => $this->customer($c) + [
            'pendingCount' => (int) $c->getAttribute('pending_count'),
            'pendingTotal' => (int) $c->getAttribute('pending_total'),
        ])->all()]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->staff($request);

        return response()->json($this->detail($customer));
    }

    public function store(Request $request): JsonResponse
    {
        $this->staff($request);
        $customer = Customer::query()->create($this->validated($request, null));
        AuditLog::record('customer.create', $customer, ['code' => $customer->code], userId: $request->user()?->id);

        return response()->json(['customer' => $this->customer($customer)], 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->staff($request);
        $customer->update($this->validated($request, $customer));
        TpvChanged::notify(['orders']);

        return response()->json(['customer' => $this->customer($customer)]);
    }

    /**
     * Recent paid tickets that can still go to a customer (not in a summary nor invoiced),
     * by number or table.
     */
    public function tickets(Request $request): JsonResponse
    {
        $this->staff($request);
        $search = trim((string) $request->query('q', ''));

        $tickets = Ticket::query()
            ->with('customer')
            ->whereNull('customer_statement_id')
            ->whereDoesntHave('invoice')
            ->where('issued_at', '>=', now()->subDays(90))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('lower(full_number) like ?', ['%'.mb_strtolower($search).'%'])->orWhereRaw('lower(table_label) like ?', ['%'.mb_strtolower($search).'%'])))
            ->latest('issued_at')
            ->limit(40)
            ->get();

        return response()->json(['tickets' => $tickets->map(fn (Ticket $t) => $this->ticket($t) + ['customerCode' => $t->customer?->code])->all()]);
    }

    public function attach(Request $request, Customer $customer): JsonResponse
    {
        $this->staff($request);
        $data = $request->validate(['ticketUuid' => ['required', 'string']]);
        $ticket = Ticket::query()->where('uuid', $data['ticketUuid'])->firstOrFail();

        abort_if($ticket->customer_statement_id !== null || $ticket->invoiced(), 422, __('tpv.customer_ticket_closed'));

        $ticket->forceFill(['customer_id' => $customer->id])->save();
        AuditLog::record('customer.ticket', $ticket, ['customer' => $customer->code], userId: $request->user()?->id);

        return response()->json($this->detail($customer));
    }

    public function detach(Request $request, Customer $customer, Ticket $ticket): JsonResponse
    {
        $this->staff($request);
        abort_if($ticket->customer_id !== $customer->id || $ticket->customer_statement_id !== null, 422, __('tpv.customer_ticket_closed'));

        $ticket->forceFill(['customer_id' => null])->save();
        AuditLog::record('customer.ticket_removed', $ticket, ['customer' => $customer->code], userId: $request->user()?->id);

        return response()->json($this->detail($customer));
    }

    public function createStatement(Request $request, Customer $customer): JsonResponse
    {
        $this->staff($request);
        $data = $request->validate(['ticketUuids' => ['array'], 'ticketUuids.*' => ['string']]);

        try {
            $statement = $this->statements->create($customer, array_values($data['ticketUuids'] ?? []), $request->user()?->id);
        } catch (RuntimeException) {
            abort(422, __('tpv.customer_nothing_pending'));
        }

        return response()->json(['statement' => $this->statements->payload($statement)], 201);
    }

    public function statement(Request $request, CustomerStatement $statement): JsonResponse
    {
        $this->staff($request);

        return response()->json(['statement' => $this->statements->payload($statement)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Customer $customer): array
    {
        return [
            'customer' => $this->customer($customer),
            'pending' => $customer->pendingTickets()->latest('issued_at')->get()->map(fn (Ticket $t) => $this->ticket($t))->all(),
            'statements' => $customer->statements()->with('invoice')->latest('issued_at')->limit(10)->get()->map(fn (CustomerStatement $s) => [
                'id' => $s->id,
                'label' => $s->label(),
                'issuedAt' => $s->issued_at->toIso8601String(),
                'total' => $s->total,
                'invoice' => $s->invoice?->full_number,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'taxId' => $customer->tax_id,
            'address' => $customer->address,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(Ticket $ticket): array
    {
        return [
            'uuid' => $ticket->uuid,
            'fullNumber' => $ticket->full_number,
            'issuedAt' => $ticket->issued_at->toIso8601String(),
            'tableLabel' => $ticket->table_label,
            'total' => $ticket->total,
        ];
    }

    /**
     * @return array{code: string, name: string, tax_id: string|null, address: string|null, email: string|null, phone: string|null}
     */
    private function validated(Request $request, ?Customer $customer): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('customers', 'code')->ignore($customer?->id)],
            'name' => ['required', 'string', 'max:160'],
            'taxId' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        return [
            'code' => trim($data['code']),
            'name' => trim($data['name']),
            'tax_id' => filled($data['taxId'] ?? null) ? strtoupper(preg_replace('/[\s.-]/', '', (string) $data['taxId']) ?? '') : null,
            'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
        ];
    }

    private function staff(Request $request): void
    {
        abort_if($request->user() === null || $request->user()->isKitchen(), 403);
    }
}
