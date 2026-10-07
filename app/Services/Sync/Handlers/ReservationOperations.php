<?php

namespace App\Services\Sync\Handlers;

use App\Models\DiningTable;
use App\Models\Reservation;
use App\Models\Zone;
use App\Services\Sync\OperationContext;
use App\Services\Sync\OperationHandler;
use App\Services\Sync\OperationRejected;
use Carbon\CarbonImmutable;

class ReservationOperations implements OperationHandler
{
    public function types(): array
    {
        return ['reservation.save', 'reservation.status'];
    }

    public function handle(string $type, array $payload, OperationContext $context): array
    {
        if ($context->operator->isKitchen()) {
            throw new OperationRejected('forbidden');
        }

        $uuid = (string) ($payload['uuid'] ?? '');

        if ($uuid === '') {
            throw new OperationRejected('invalid_payload', ['field' => 'uuid']);
        }

        $context->touch('reservations');

        if ($type === 'reservation.status') {
            $reservation = Reservation::query()->where('uuid', $uuid)->first();
            $status = (string) ($payload['status'] ?? '');

            if ($reservation === null) {
                throw new OperationRejected('reservation_not_found');
            }

            if (! in_array($status, Reservation::STATUSES, true)) {
                throw new OperationRejected('invalid_status');
            }

            $reservation->forceFill(['status' => $status])->save();

            return [];
        }

        $name = trim((string) ($payload['name'] ?? ''));

        try {
            $reservedAt = CarbonImmutable::parse((string) ($payload['reservedAt'] ?? ''));
        } catch (\Throwable) {
            throw new OperationRejected('invalid_payload', ['field' => 'reservedAt']);
        }

        if ($name === '') {
            throw new OperationRejected('invalid_payload', ['field' => 'name']);
        }

        $tableId = is_numeric($payload['tableId'] ?? null) && DiningTable::query()->whereKey($payload['tableId'])->exists() ? (int) $payload['tableId'] : null;
        $zoneId = is_numeric($payload['zoneId'] ?? null) && Zone::query()->whereKey($payload['zoneId'])->exists() ? (int) $payload['zoneId'] : null;

        $reservation = Reservation::withTrashed()->firstOrNew(['uuid' => $uuid]);
        $reservation->fill([
            'name' => mb_substr($name, 0, 120),
            'phone' => is_string($payload['phone'] ?? null) ? mb_substr($payload['phone'], 0, 30) : null,
            'party_size' => max(1, (int) ($payload['partySize'] ?? 2)),
            'reserved_at' => $reservedAt,
            'duration_minutes' => max(15, (int) ($payload['durationMinutes'] ?? 90)),
            'zone_id' => $zoneId ?? ($tableId ? DiningTable::query()->whereKey($tableId)->value('zone_id') : null),
            'dining_table_id' => $tableId,
            'notes' => is_string($payload['notes'] ?? null) ? mb_substr($payload['notes'], 0, 1000) : null,
        ]);

        if (! $reservation->exists) {
            $reservation->status = 'confirmed';
            $reservation->source = 'staff';
            $reservation->created_by = $context->operator->id;
        }

        $reservation->save();

        return ['id' => $reservation->id];
    }
}
