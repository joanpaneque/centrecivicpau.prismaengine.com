<?php

namespace App\Services\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\Ticket;
use App\Models\TicketLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customer summaries: group the pending tickets of a customer into one printed summary
 * whose QR asks for a single full invoice of all of them.
 */
class StatementService
{
    /**
     * @param  list<string>  $ticketUuids  empty = all the pending ones
     */
    public function create(Customer $customer, array $ticketUuids, ?int $userId = null): CustomerStatement
    {
        return DB::transaction(function () use ($customer, $ticketUuids, $userId) {
            $query = $customer->pendingTickets()->lockForUpdate();

            if ($ticketUuids !== []) {
                $query->whereIn('uuid', $ticketUuids);
            }

            /** @var Collection<int, Ticket> $tickets */
            $tickets = $query->get();

            if ($tickets->isEmpty()) {
                throw new RuntimeException('no_pending_tickets');
            }

            $statement = CustomerStatement::query()->create([
                'customer_id' => $customer->id,
                'public_token' => bin2hex(random_bytes(16)),
                'issued_at' => now(),
                'total' => (int) $tickets->sum('total'),
                'created_by' => $userId,
            ]);

            Ticket::query()->whereKey($tickets->modelKeys())->update(['customer_statement_id' => $statement->id]);
            AuditLog::record('customer.statement', $statement, ['customer' => $customer->code, 'tickets' => $tickets->pluck('full_number')->all()], userId: $userId);

            return $statement;
        });
    }

    /**
     * VAT of all the tickets together, by rate.
     *
     * @param  iterable<Ticket>  $tickets
     * @return list<array{rate: float, base: int, vat: int, total: int}>
     */
    public function vatBreakdown(iterable $tickets): array
    {
        $rows = [];

        foreach ($tickets as $ticket) {
            foreach ($ticket->vat_breakdown ?? [] as $row) {
                $key = (string) (float) $row['rate'];
                $rows[$key] ??= ['rate' => (float) $row['rate'], 'base' => 0, 'vat' => 0, 'total' => 0];
                $rows[$key]['base'] += (int) $row['base'];
                $rows[$key]['vat'] += (int) $row['vat'];
                $rows[$key]['total'] += (int) $row['total'];
            }
        }

        ksort($rows, SORT_NUMERIC);

        return array_values($rows);
    }

    /**
     * What the TPV prints: the customer, every ticket with its lines, the VAT of everything
     * and the link of the QR.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerStatement $statement): array
    {
        $statement->loadMissing('customer', 'tickets.lines', 'invoice');

        return [
            'id' => $statement->id,
            'label' => $statement->label(),
            'issuedAt' => $statement->issued_at->toIso8601String(),
            'total' => $statement->total,
            'customer' => ['code' => $statement->customer->code, 'name' => $statement->customer->name, 'taxId' => $statement->customer->tax_id],
            'invoiceUrl' => route('invoice-request.statement', $statement->public_token),
            'invoice' => $statement->invoice?->full_number,
            'vatBreakdown' => $this->vatBreakdown($statement->tickets),
            'tickets' => $statement->tickets->map(fn (Ticket $t) => [
                'fullNumber' => $t->full_number,
                'issuedAt' => $t->issued_at->toIso8601String(),
                'tableLabel' => $t->table_label,
                'total' => $t->total,
                'lines' => $t->lines->map(fn (TicketLine $l) => [
                    'name' => $l->name,
                    'quantity' => $l->quantity,
                    'unitPrice' => $l->unit_price,
                    'total' => $l->total,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
