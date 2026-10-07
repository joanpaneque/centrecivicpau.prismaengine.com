<?php

namespace App\Services\Sync\Handlers;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketSeries;
use App\Services\Cashier\CashSessionSummary;
use App\Services\Sync\OperationContext;
use App\Services\Sync\OperationHandler;
use App\Services\Sync\OperationRejected;
use App\Services\Tickets\TaxCalculator;
use App\Services\Tickets\VerifactuService;
use App\Support\AppSettings;
use App\Support\Translation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A ticket is a fiscal document that has already been handed to the customer when the
 * operation arrives, so it is never refused for business reasons: inconsistencies
 * (order already paid elsewhere, totals mismatch) are stored and flagged in the audit log.
 */
class CashierOperations implements OperationHandler
{
    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly VerifactuService $verifactu,
        private readonly CashSessionSummary $summary,
    ) {}

    public function types(): array
    {
        return ['cash.open', 'cash.close', 'ticket.issue'];
    }

    public function handle(string $type, array $payload, OperationContext $context): array
    {
        if (! $context->device?->isCashier()) {
            throw new OperationRejected('cashier_device_required');
        }

        $context->touch('cashier');

        return match ($type) {
            'cash.open' => $this->open($payload, $context),
            'cash.close' => $this->close($payload, $context),
            default => $this->issue($payload, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function open(array $payload, OperationContext $context): array
    {
        $uuid = (string) ($payload['sessionUuid'] ?? '');

        if ($uuid === '') {
            throw new OperationRejected('invalid_payload', ['field' => 'sessionUuid']);
        }

        $session = CashSession::query()->firstOrCreate(['uuid' => $uuid], [
            'device_id' => $context->device?->id,
            'opened_by' => $context->operator->id,
            'opened_at' => $this->time($payload['openedAt'] ?? null, $context),
            'opening_float' => max(0, (int) ($payload['openingFloat'] ?? 0)),
        ]);

        return ['id' => $session->id];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function close(array $payload, OperationContext $context): array
    {
        $session = CashSession::query()->where('uuid', (string) ($payload['sessionUuid'] ?? ''))->lockForUpdate()->first();

        if ($session === null) {
            throw new OperationRejected('cash_session_not_found');
        }

        if ($session->closed_at !== null) {
            return ['summary' => $session->summary, 'zNumber' => $session->z_number];
        }

        /** @var array<string, int> $count */
        $count = array_map('intval', array_filter((array) ($payload['cashCount'] ?? []), 'is_numeric'));
        $counted = array_key_exists('countedCash', $payload)
            ? (int) $payload['countedCash']
            : array_sum(array_map(fn ($denomination, $qty) => (int) $denomination * $qty, array_keys($count), $count));

        $closedAt = $this->time($payload['closedAt'] ?? null, $context);
        $summary = $this->summary->build($session, $closedAt);
        $expected = $summary['expected_cash'];

        $session->forceFill([
            'closed_by' => $context->operator->id,
            'closed_at' => $closedAt,
            'cash_count' => $count,
            'counted_cash' => $counted,
            'expected_cash' => $expected,
            'difference' => $counted - $expected,
            'summary' => $summary,
            'z_number' => (int) CashSession::query()->where('device_id', $session->device_id)->max('z_number') + 1,
            'notes' => is_string($payload['notes'] ?? null) ? mb_substr($payload['notes'], 0, 250) : null,
        ])->save();

        return ['summary' => $summary, 'zNumber' => $session->z_number, 'difference' => $session->difference];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function issue(array $payload, OperationContext $context): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($payload['ticket'] ?? null) ? $payload['ticket'] : [];
        $uuid = (string) ($data['uuid'] ?? '');

        if ($uuid === '') {
            throw new OperationRejected('invalid_payload', ['field' => 'ticket.uuid']);
        }

        if ($existing = Ticket::query()->where('uuid', $uuid)->first()) {
            return ['ticketId' => $existing->id, 'fullNumber' => $existing->full_number];
        }

        $series = TicketSeries::query()->where('code', (string) ($data['seriesCode'] ?? ''))->lockForUpdate()->first()
            ?? ($context->device ? TicketSeries::query()->where('device_id', $context->device->id)->where('kind', 'simplified')->lockForUpdate()->first() : null);

        if ($series === null) {
            throw new OperationRejected('series_not_found');
        }

        $number = max(1, (int) ($data['number'] ?? 0));
        $renumbered = false;

        if (Ticket::query()->where('ticket_series_id', $series->id)->where('number', $number)->exists()) {
            $number = $series->last_number + 1;
            $renumbered = true;
        }

        $lines = $this->lines($data['lines'] ?? []);
        $surchargeRate = (float) ($data['surchargeRate'] ?? 0);
        $computed = $this->tax->compute(array_map(fn ($l) => ['total' => $l['total'], 'vat_rate' => $l['vat_rate']], $lines), $surchargeRate);
        $total = (int) ($data['total'] ?? $computed['total']);

        $order = is_string($data['orderUuid'] ?? null) ? Order::query()->where('uuid', $data['orderUuid'])->first() : null;
        $session = is_string($data['cashSessionUuid'] ?? null) ? CashSession::query()->where('uuid', $data['cashSessionUuid'])->first() : null;

        $ticket = Ticket::query()->create([
            'uuid' => $uuid,
            'ticket_series_id' => $series->id,
            'number' => $number,
            'full_number' => $series->format($number),
            'order_id' => $order?->id,
            'cash_session_id' => $session?->id,
            'device_id' => $context->device?->id,
            'table_label' => is_string($data['tableLabel'] ?? null) ? $data['tableLabel'] : null,
            'waiter_id' => is_numeric($data['waiterId'] ?? null) ? (int) $data['waiterId'] : $order?->opened_by,
            'cashier_id' => $context->operator->id,
            'issued_at' => $this->time($data['issuedAt'] ?? null, $context),
            'subtotal' => $computed['subtotal'],
            'surcharge_rate' => $surchargeRate,
            'surcharge_amount' => $computed['surcharge_amount'],
            'discount_total' => (int) array_sum(array_column($lines, 'discount_amount')),
            'total' => $total,
            'vat_breakdown' => $computed['vat_breakdown'],
            'issuer' => AppSettings::issuer(),
            'public_token' => is_string($data['publicToken'] ?? null) && strlen($data['publicToken']) >= 16 ? $data['publicToken'] : bin2hex(random_bytes(16)),
        ]);

        foreach ($lines as $line) {
            $ticket->lines()->create($line);
        }

        foreach ((array) ($data['payments'] ?? []) as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            Payment::query()->create([
                'uuid' => is_string($payment['uuid'] ?? null) ? $payment['uuid'] : (string) Str::uuid(),
                'ticket_id' => $ticket->id,
                'method' => ($payment['method'] ?? 'cash') === 'card' ? 'card' : 'cash',
                'amount' => (int) ($payment['amount'] ?? 0),
                'tendered' => is_numeric($payment['tendered'] ?? null) ? (int) $payment['tendered'] : null,
                'change' => is_numeric($payment['change'] ?? null) ? (int) $payment['change'] : null,
            ]);
        }

        $series->forceFill(['last_number' => max($series->last_number, $number)])->save();
        $this->verifactu->chain($ticket);

        if ($renumbered) {
            AuditLog::record('ticket.renumbered', $ticket, ['requested' => $data['number'] ?? null, 'assigned' => $number], userId: $context->operator->id, deviceId: $context->device?->id);
        }

        if ($total !== $computed['total']) {
            AuditLog::record('ticket.total_mismatch', $ticket, ['client' => $total, 'server' => $computed['total']], userId: $context->operator->id, deviceId: $context->device?->id);
        }

        if ($order) {
            $this->settleOrder($order, $payload, $ticket, $context);
        }

        $context->touch('orders');

        return ['ticketId' => $ticket->id, 'fullNumber' => $ticket->full_number, 'renumbered' => $renumbered];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function settleOrder(Order $order, array $payload, Ticket $ticket, OperationContext $context): void
    {
        if (in_array($order->status, ['paid', 'cancelled'], true)) {
            AuditLog::record('ticket.order_already_closed', $ticket, ['order' => $order->uuid, 'status' => $order->status], userId: $context->operator->id, deviceId: $context->device?->id);
        }

        foreach ((array) ($payload['paidLines'] ?? []) as $paid) {
            if (! is_array($paid) || ! is_string($paid['lineUuid'] ?? null)) {
                continue;
            }

            $line = OrderLine::query()->where('uuid', $paid['lineUuid'])->first();

            if ($line) {
                $quantity = min($line->quantity, $line->paid_quantity + max(0, (int) ($paid['quantity'] ?? $line->quantity)));
                $line->forceFill(['paid_quantity' => $quantity])->save();
                $line->children()->update(['paid_quantity' => DB::raw('quantity')]);
            }
        }

        $pending = $order->lines()
            ->whereNull('voided_at')
            ->whereNull('parent_line_id')
            ->whereColumn('paid_quantity', '<', 'quantity')
            ->exists();

        if (! $pending || ! empty($payload['closeOrder'])) {
            $order->forceFill(['status' => 'paid', 'closed_at' => $ticket->issued_at])->save();
        } else {
            $order->touch();
        }
    }

    /**
     * @return list<array{order_line_id: int|null, name: array{ca: string, es: string}, quantity: float, unit_price: int, vat_rate: float, discount_amount: int, total: int, modifiers: list<mixed>|null}>
     */
    private function lines(mixed $lines): array
    {
        $result = [];

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $orderLineId = is_string($line['orderLineUuid'] ?? null)
                ? OrderLine::query()->where('uuid', $line['orderLineUuid'])->value('id')
                : null;

            $result[] = [
                'order_line_id' => is_numeric($orderLineId) ? (int) $orderLineId : null,
                'name' => Translation::normalize(is_array($line['name'] ?? null) || is_string($line['name'] ?? null) ? $line['name'] : ''),
                'quantity' => round((float) ($line['quantity'] ?? 1), 3),
                'unit_price' => (int) ($line['unitPrice'] ?? 0),
                'vat_rate' => (float) ($line['vatRate'] ?? 10),
                'discount_amount' => (int) ($line['discountAmount'] ?? 0),
                'total' => (int) ($line['total'] ?? 0),
                'modifiers' => is_array($line['modifiers'] ?? null) ? array_values($line['modifiers']) : null,
            ];
        }

        return $result;
    }

    private function time(mixed $value, OperationContext $context): CarbonImmutable
    {
        if (is_string($value)) {
            try {
                $time = CarbonImmutable::parse($value);

                return $time->isAfter(now()->addMinutes(5)) ? CarbonImmutable::now() : $time;
            } catch (\Throwable) {
            }
        }

        return $context->clientTime;
    }
}
