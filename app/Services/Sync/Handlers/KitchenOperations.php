<?php

namespace App\Services\Sync\Handlers;

use App\Models\KitchenTicket;
use App\Services\Sync\OperationContext;
use App\Services\Sync\OperationHandler;
use App\Services\Sync\OperationRejected;

class KitchenOperations implements OperationHandler
{
    public function types(): array
    {
        return ['kds.status'];
    }

    public function handle(string $type, array $payload, OperationContext $context): array
    {
        $ticket = KitchenTicket::query()->where('uuid', (string) ($payload['ticketUuid'] ?? ''))->first();

        if ($ticket === null) {
            throw new OperationRejected('kitchen_ticket_not_found');
        }

        $status = (string) ($payload['status'] ?? '');

        if (! in_array($status, KitchenTicket::STATUSES, true)) {
            throw new OperationRejected('invalid_status');
        }

        $at = $context->clientTime;

        $ticket->forceFill(match ($status) {
            'pending' => ['status' => 'pending', 'started_at' => null, 'ready_at' => null, 'served_at' => null],
            'preparing' => ['status' => 'preparing', 'started_at' => $ticket->started_at ?? $at, 'ready_at' => null, 'served_at' => null],
            'ready' => ['status' => 'ready', 'held' => false, 'ready_at' => $at, 'served_at' => null],
            default => ['status' => 'served', 'served_at' => $at],
        })->save();

        $context->touch('kitchen');

        return ['status' => $ticket->status];
    }
}
