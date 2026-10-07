<?php

namespace App\Services\Cashier;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\Ticket;
use Carbon\CarbonInterface;

/**
 * Z report figures for a cash session. The cashier computes the same preview offline
 * from its local tickets; the server value is the one stored.
 */
class CashSessionSummary
{
    /**
     * @return array{tickets: int, total: int, by_method: array<string, int>, discounts: int, surcharge: int, voids: array{count: int, amount: int}, vat_breakdown: list<array{rate: float, base: int, vat: int, total: int}>, opening_float: int, expected_cash: int, first_ticket: string|null, last_ticket: string|null}
     */
    public function build(CashSession $session, CarbonInterface $until): array
    {
        $tickets = Ticket::query()->where('cash_session_id', $session->id)->orderBy('number')->get();
        $ticketIds = $tickets->pluck('id');

        $byMethod = ['cash' => 0, 'card' => 0];

        foreach (Payment::query()->whereIn('ticket_id', $ticketIds)->get() as $payment) {
            $byMethod[$payment->method] = ($byMethod[$payment->method] ?? 0) + $payment->amount;
        }

        /** @var array<string, array{rate: float, base: int, vat: int, total: int}> $vat */
        $vat = [];

        foreach ($tickets as $ticket) {
            foreach ($ticket->vat_breakdown as $row) {
                $key = number_format((float) $row['rate'], 2, '.', '');
                $vat[$key] ??= ['rate' => (float) $row['rate'], 'base' => 0, 'vat' => 0, 'total' => 0];
                $vat[$key]['base'] += (int) $row['base'];
                $vat[$key]['vat'] += (int) $row['vat'];
                $vat[$key]['total'] += (int) $row['total'];
            }
        }

        ksort($vat);

        $voids = AuditLog::query()
            ->where('action', 'line.void')
            ->whereBetween('created_at', [$session->opened_at, $until])
            ->get();

        return [
            'tickets' => $tickets->count(),
            'total' => (int) $tickets->sum('total'),
            'by_method' => $byMethod,
            'discounts' => (int) $tickets->sum('discount_total'),
            'surcharge' => (int) $tickets->sum('surcharge_amount'),
            'voids' => [
                'count' => $voids->count(),
                'amount' => (int) $voids->sum(fn (AuditLog $log) => (int) ($log->data['amount'] ?? 0)),
            ],
            'vat_breakdown' => array_values($vat),
            'opening_float' => $session->opening_float,
            'expected_cash' => $session->opening_float + $byMethod['cash'],
            'first_ticket' => $tickets->first()?->full_number,
            'last_ticket' => $tickets->last()?->full_number,
        ];
    }
}
