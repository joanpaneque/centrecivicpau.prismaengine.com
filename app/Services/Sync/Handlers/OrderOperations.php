<?php

namespace App\Services\Sync\Handlers;

use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\Sync\OperationContext;
use App\Services\Sync\OperationHandler;
use App\Services\Sync\OperationRejected;
use App\Support\Translation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Orders are merged rather than rejected when two devices open the same table offline:
 * the later order is marked as merged into the earlier one and its lines follow it, so no
 * line taken by a waiter is ever lost.
 */
class OrderOperations implements OperationHandler
{
    public function types(): array
    {
        return [
            'order.open', 'order.send', 'order.march', 'order.update', 'order.move', 'order.merge',
            'order.requestBill', 'order.reopen', 'order.cancel', 'order.discount',
            'line.void', 'line.discount', 'product.soldOut', 'print.job',
        ];
    }

    public function handle(string $type, array $payload, OperationContext $context): array
    {
        if ($context->operator->isKitchen() && $type !== 'print.job') {
            throw new OperationRejected('forbidden');
        }

        return match ($type) {
            'order.open' => $this->open($payload, $context),
            'order.send' => $this->send($payload, $context),
            'order.march' => $this->march($payload, $context),
            'order.update' => $this->update($payload, $context),
            'order.move' => $this->move($payload, $context),
            'order.merge' => $this->merge($payload, $context),
            'order.requestBill' => $this->setStatus($payload, $context, 'bill_requested'),
            'order.reopen' => $this->setStatus($payload, $context, 'open'),
            'order.cancel' => $this->cancel($payload, $context),
            'order.discount' => $this->discountOrder($payload, $context),
            'line.void' => $this->voidLine($payload, $context),
            'line.discount' => $this->discountLine($payload, $context),
            'product.soldOut' => $this->soldOut($payload, $context),
            'print.job' => [],
            default => throw new OperationRejected('unknown_operation'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function open(array $payload, OperationContext $context): array
    {
        $order = $this->openOrder($payload, $context);

        return ['orderUuid' => $order->uuid, 'mergedInto' => $order->uuid !== $payload['orderUuid'] ? $order->uuid : null];
    }

    /**
     * Find the order or create it; if the table already has another active order, the
     * new one is recorded as merged into it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function openOrder(array $payload, OperationContext $context): Order
    {
        $uuid = $this->requireString($payload, 'orderUuid');
        $existing = Order::query()->where('uuid', $uuid)->first();

        if ($existing) {
            return $this->follow($existing);
        }

        $tableId = is_numeric($payload['tableId'] ?? null) ? (int) $payload['tableId'] : null;

        if ($tableId !== null && ! DiningTable::withTrashed()->whereKey($tableId)->exists()) {
            $tableId = null;
        }

        $openedAt = $this->time($payload['openedAt'] ?? null, $context);
        $active = $tableId !== null
            ? Order::query()->where('dining_table_id', $tableId)->whereIn('status', Order::ACTIVE_STATUSES)->lockForUpdate()->first()
            : null;

        $order = Order::query()->create([
            'uuid' => $uuid,
            'dining_table_id' => $tableId,
            'status' => $active ? 'merged' : 'open',
            'guests' => is_numeric($payload['guests'] ?? null) ? (int) $payload['guests'] : null,
            'label' => is_string($payload['label'] ?? null) ? mb_substr($payload['label'], 0, 60) : null,
            'opened_by' => $context->operator->id,
            'opened_at' => $openedAt,
            'merged_into_id' => $active?->id,
            'closed_at' => $active ? now() : null,
            'reservation_uuid' => is_string($payload['reservationUuid'] ?? null) ? $payload['reservationUuid'] : null,
        ]);

        if (is_string($payload['reservationUuid'] ?? null)) {
            Reservation::query()->where('uuid', $payload['reservationUuid'])->update([
                'status' => 'seated',
                'order_uuid' => ($active ?? $order)->uuid,
                'dining_table_id' => $tableId,
            ]);
            $context->touch('reservations');
        }

        $context->touch('orders');

        return $active ?? $order;
    }

    private function follow(Order $order): Order
    {
        $guard = 0;

        while ($order->status === 'merged' && $order->merged_into_id !== null && $guard++ < 10) {
            $next = Order::query()->find($order->merged_into_id);

            if ($next === null) {
                break;
            }

            $order = $next;
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findOrder(array $payload, string $key = 'orderUuid'): Order
    {
        $order = Order::query()->where('uuid', $this->requireString($payload, $key))->first();

        if ($order === null) {
            throw new OperationRejected('order_not_found');
        }

        return $this->follow($order);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(array $payload, OperationContext $context): array
    {
        $order = $this->openOrder($payload, $context);

        if (! $order->isActive()) {
            $order->forceFill(['status' => 'open', 'closed_at' => null])->save();
        }

        $sentAt = $this->time($payload['sentAt'] ?? null, $context);
        $created = 0;

        foreach ((array) ($payload['lines'] ?? []) as $line) {
            if (is_array($line)) {
                $created += $this->createLine($order, $line, null, $context, $sentAt);
            }
        }

        foreach ((array) ($payload['kitchenTickets'] ?? []) as $ticket) {
            if (is_array($ticket)) {
                $this->createKitchenTicket($order, $ticket, $context, $sentAt);
            }
        }

        if ($order->status === 'bill_requested') {
            $order->forceFill(['status' => 'open', 'bill_requested_at' => null])->save();
        }

        $order->touch();
        $context->touch('orders', 'kitchen', 'catalog');

        return ['orderUuid' => $order->uuid, 'lines' => $created];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLine(Order $order, array $data, ?OrderLine $parent, OperationContext $context, CarbonImmutable $sentAt): int
    {
        $uuid = $this->requireString($data, 'uuid');

        if (OrderLine::query()->where('uuid', $uuid)->exists()) {
            return 0;
        }

        $productId = is_numeric($data['productId'] ?? null) ? (int) $data['productId'] : null;
        $product = $productId ? Product::withTrashed()->find($productId) : null;
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        $line = OrderLine::query()->create([
            'uuid' => $uuid,
            'order_id' => $order->id,
            'parent_line_id' => $parent?->id,
            'product_id' => $product?->id,
            'set_menu_id' => is_numeric($data['setMenuId'] ?? null) ? (int) $data['setMenuId'] : null,
            'production_destination_id' => is_numeric($data['destinationId'] ?? null) ? (int) $data['destinationId'] : null,
            'name' => Translation::normalize(is_array($data['name'] ?? null) ? $data['name'] : ($product->name ?? '')),
            'quantity' => $quantity,
            'unit_price' => (int) ($data['unitPrice'] ?? $product->price ?? 0),
            'vat_rate' => (float) ($data['vatRate'] ?? $product->vat_rate ?? 10),
            'modifiers' => $this->modifiers($data['modifiers'] ?? []),
            'note' => is_string($data['note'] ?? null) && $data['note'] !== '' ? mb_substr($data['note'], 0, 250) : null,
            'course' => in_array($data['course'] ?? null, [1, 2, 3], true) ? $data['course'] : null,
            'created_by' => $context->operator->id,
            'sent_at' => $sentAt,
        ]);

        if ($product && $parent === null) {
            Product::withTrashed()->whereKey($product->id)->increment('order_count', $quantity);
        }

        $count = 1;

        foreach ((array) ($data['children'] ?? []) as $child) {
            if (is_array($child)) {
                $count += $this->createLine($order, $child, $line, $context, $sentAt);
            }
        }

        return $count;
    }

    /**
     * @return list<array{id: int|null, name: array{ca: string, es: string}, price_delta: int}>
     */
    private function modifiers(mixed $modifiers): array
    {
        if (! is_array($modifiers)) {
            return [];
        }

        $result = [];

        foreach ($modifiers as $modifier) {
            if (! is_array($modifier)) {
                continue;
            }

            $result[] = [
                'id' => is_numeric($modifier['id'] ?? null) ? (int) $modifier['id'] : null,
                'name' => Translation::normalize(is_array($modifier['name'] ?? null) || is_string($modifier['name'] ?? null) ? $modifier['name'] : ''),
                'price_delta' => (int) ($modifier['priceDelta'] ?? $modifier['price_delta'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createKitchenTicket(Order $order, array $data, OperationContext $context, CarbonImmutable $sentAt): void
    {
        $uuid = $this->requireString($data, 'uuid');

        if (KitchenTicket::query()->where('uuid', $uuid)->exists() || ! is_numeric($data['destinationId'] ?? null)) {
            return;
        }

        $order->loadMissing('table');

        $ticket = KitchenTicket::query()->create([
            'uuid' => $uuid,
            'order_id' => $order->id,
            'production_destination_id' => (int) $data['destinationId'],
            'table_label' => is_string($data['tableLabel'] ?? null) ? $data['tableLabel'] : ($order->table->label ?? $order->label),
            'course' => is_numeric($data['course'] ?? null) ? (int) $data['course'] : null,
            'held' => (bool) ($data['held'] ?? false),
            'status' => 'pending',
            'created_by' => $context->operator->id,
            'sent_at' => $sentAt,
        ]);

        $lineUuids = array_values(array_filter((array) ($data['lineUuids'] ?? []), 'is_string'));
        $lines = OrderLine::query()->whereIn('uuid', $lineUuids)->get();

        foreach ($lines as $line) {
            KitchenTicketItem::query()->create([
                'kitchen_ticket_id' => $ticket->id,
                'order_line_id' => $line->id,
                'name' => $line->name,
                'quantity' => $line->quantity,
                'modifiers' => $line->modifiers,
                'note' => $line->note,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function march(array $payload, OperationContext $context): array
    {
        $order = $this->findOrder($payload);
        $course = (int) ($payload['course'] ?? 2);

        $count = KitchenTicket::query()
            ->where('order_id', $order->id)
            ->where('course', $course)
            ->where('held', true)
            ->update(['held' => false, 'sent_at' => now(), 'updated_at' => now()]);

        AuditLog::record('order.march', $order, ['course' => $course], userId: $context->operator->id, deviceId: $context->device?->id);
        $order->touch();
        $context->touch('orders', 'kitchen');

        return ['tickets' => $count];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function update(array $payload, OperationContext $context): array
    {
        $order = $this->findOrder($payload);

        $order->fill(array_filter([
            'guests' => is_numeric($payload['guests'] ?? null) ? (int) $payload['guests'] : null,
            'label' => is_string($payload['label'] ?? null) ? mb_substr($payload['label'], 0, 60) : null,
        ], fn ($v) => $v !== null))->save();

        $context->touch('orders');

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function move(array $payload, OperationContext $context): array
    {
        $order = $this->findOrder($payload);
        $tableId = (int) ($payload['toTableId'] ?? 0);

        if (! DiningTable::query()->whereKey($tableId)->exists()) {
            throw new OperationRejected('table_not_found');
        }

        if (! $order->isActive()) {
            throw new OperationRejected('order_closed');
        }

        $target = Order::query()
            ->where('dining_table_id', $tableId)
            ->whereIn('status', Order::ACTIVE_STATUSES)
            ->whereKeyNot($order->id)
            ->lockForUpdate()
            ->first();

        $from = $order->dining_table_id;

        if ($target) {
            $this->mergeInto($order, $target, $context);
            $result = ['orderUuid' => $target->uuid, 'merged' => true];
        } else {
            $order->forceFill(['dining_table_id' => $tableId])->save();
            KitchenTicket::query()->where('order_id', $order->id)->update([
                'table_label' => DiningTable::query()->whereKey($tableId)->value('label'),
                'updated_at' => now(),
            ]);
            $result = ['orderUuid' => $order->uuid, 'merged' => false];
        }

        AuditLog::record('order.move', $order, ['from' => $from, 'to' => $tableId], userId: $context->operator->id, deviceId: $context->device?->id);
        $context->touch('orders', 'kitchen');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function merge(array $payload, OperationContext $context): array
    {
        $source = $this->findOrder($payload);
        $target = $this->findOrder($payload, 'intoOrderUuid');

        if ($source->is($target)) {
            return ['orderUuid' => $target->uuid];
        }

        if (! $source->isActive() || ! $target->isActive()) {
            throw new OperationRejected('order_closed');
        }

        $this->mergeInto($source, $target, $context);
        $context->touch('orders', 'kitchen');

        return ['orderUuid' => $target->uuid];
    }

    private function mergeInto(Order $source, Order $target, OperationContext $context): void
    {
        OrderLine::query()->where('order_id', $source->id)->update(['order_id' => $target->id, 'updated_at' => now()]);
        $target->loadMissing('table');
        KitchenTicket::query()->where('order_id', $source->id)->update([
            'order_id' => $target->id,
            'table_label' => $target->table->label ?? $target->label,
            'updated_at' => now(),
        ]);

        $source->forceFill(['status' => 'merged', 'merged_into_id' => $target->id, 'closed_at' => now()])->save();
        $target->forceFill([
            'guests' => ($target->guests ?? 0) + ($source->guests ?? 0) ?: null,
            'opened_at' => $source->opened_at->lt($target->opened_at) ? $source->opened_at : $target->opened_at,
        ])->save();

        AuditLog::record('order.merge', $target, ['source' => $source->uuid], userId: $context->operator->id, deviceId: $context->device?->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function setStatus(array $payload, OperationContext $context, string $status): array
    {
        $order = $this->findOrder($payload);

        if (! $order->isActive()) {
            throw new OperationRejected('order_closed');
        }

        $order->forceFill([
            'status' => $status,
            'bill_requested_at' => $status === 'bill_requested' ? now() : null,
        ])->save();

        $context->touch('orders');

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cancel(array $payload, OperationContext $context): array
    {
        $order = $this->findOrder($payload);

        $pending = $order->lines()->whereNull('voided_at')->whereNull('parent_line_id')->whereColumn('paid_quantity', '<', 'quantity')->exists();

        if ($pending) {
            throw new OperationRejected('order_not_empty');
        }

        $order->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();
        AuditLog::record('order.cancel', $order, [], $this->optionalString($payload, 'reason'), $context->operator->id, $context->device?->id);
        $context->touch('orders');

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function voidLine(array $payload, OperationContext $context): array
    {
        $line = OrderLine::query()->where('uuid', $this->requireString($payload, 'lineUuid'))->lockForUpdate()->first();

        if ($line === null) {
            throw new OperationRejected('line_not_found');
        }

        if ($line->voided_at !== null) {
            return [];
        }

        $available = $line->quantity - $line->paid_quantity;
        $quantity = min($available, max(1, (int) ($payload['quantity'] ?? $available)));

        if ($quantity <= 0) {
            throw new OperationRejected('line_already_paid');
        }

        $reason = $this->optionalString($payload, 'reason');
        $voidFields = ['voided_at' => now(), 'voided_by' => $context->operator->id, 'void_reason' => $reason];

        if ($quantity >= $line->quantity) {
            $line->forceFill($voidFields)->save();
            $line->children()->update($voidFields + ['updated_at' => now()]);
            KitchenTicketItem::query()->where('order_line_id', $line->id)->update(['voided' => true]);
        } else {
            $line->forceFill(['quantity' => $line->quantity - $quantity])->save();
            $voided = $line->replicate(['uuid', 'paid_quantity']);
            $voided->forceFill([
                'uuid' => $this->optionalString($payload, 'splitUuid') ?? (string) Str::uuid(),
                'quantity' => $quantity,
                'paid_quantity' => 0,
            ] + $voidFields)->save();
            KitchenTicketItem::query()->where('order_line_id', $line->id)->decrement('quantity', $quantity);
        }

        KitchenTicket::query()
            ->whereIn('id', KitchenTicketItem::query()->where('order_line_id', $line->id)->select('kitchen_ticket_id'))
            ->update(['updated_at' => now()]);

        AuditLog::record('line.void', $line, [
            'quantity' => $quantity,
            'name' => $line->translated(),
            'amount' => $line->unitTotal() * $quantity,
        ], $reason, $context->operator->id, $context->device?->id);

        $line->order->touch();
        $context->touch('orders', 'kitchen');

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function discountLine(array $payload, OperationContext $context): array
    {
        $line = OrderLine::query()->where('uuid', $this->requireString($payload, 'lineUuid'))->first();

        if ($line === null) {
            throw new OperationRejected('line_not_found');
        }

        $this->applyDiscount($line, $payload, $context);
        $line->order->touch();
        $context->touch('orders');

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function discountOrder(array $payload, OperationContext $context): array
    {
        $order = $this->findOrder($payload);

        foreach ($order->lines()->whereNull('voided_at')->whereNull('parent_line_id')->get() as $line) {
            $this->applyDiscount($line, ['type' => 'percent', 'value' => $payload['percent'] ?? 0, 'reason' => $payload['reason'] ?? null], $context);
        }

        $order->touch();
        $context->touch('orders');

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyDiscount(OrderLine $line, array $payload, OperationContext $context): void
    {
        $type = in_array($payload['type'] ?? null, ['percent', 'amount'], true) ? $payload['type'] : null;
        $value = (float) ($payload['value'] ?? 0);

        if ($type === null || $value <= 0) {
            $line->forceFill(['discount_type' => null, 'discount_value' => 0, 'discount_reason' => null, 'discounted_by' => null])->save();

            return;
        }

        $stored = $type === 'percent' ? (int) round(min(100, $value) * 100) : (int) round($value);
        $reason = $this->optionalString($payload, 'reason');

        $line->forceFill([
            'discount_type' => $type,
            'discount_value' => $stored,
            'discount_reason' => $reason,
            'discounted_by' => $context->operator->id,
        ])->save();

        AuditLog::record('line.discount', $line, [
            'type' => $type,
            'value' => $stored,
            'amount' => $line->discountAmount(),
            'name' => $line->translated(),
        ], $reason, $context->operator->id, $context->device?->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function soldOut(array $payload, OperationContext $context): array
    {
        $product = Product::query()->find((int) ($payload['productId'] ?? 0));

        if ($product === null) {
            throw new OperationRejected('product_not_found');
        }

        $product->forceFill(['sold_out' => (bool) ($payload['soldOut'] ?? true)])->save();
        AuditLog::record('product.sold_out', $product, ['sold_out' => $product->sold_out], userId: $context->operator->id, deviceId: $context->device?->id);
        $context->touch('catalog');

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requireString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || $value === '') {
            throw new OperationRejected('invalid_payload', ['field' => $key]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 250) : null;
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
