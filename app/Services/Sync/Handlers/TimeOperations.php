<?php

namespace App\Services\Sync\Handlers;

use App\Models\TimeEntry;
use App\Services\Sync\OperationContext;
use App\Services\Sync\OperationHandler;
use App\Services\Sync\OperationRejected;
use App\Services\TimeTracking\ClockQrService;
use App\Services\TimeTracking\TimeEntryRecorder;
use Carbon\CarbonImmutable;

class TimeOperations implements OperationHandler
{
    public function __construct(
        private readonly TimeEntryRecorder $recorder,
        private readonly ClockQrService $qr,
    ) {}

    public function types(): array
    {
        return ['time.clock'];
    }

    public function handle(string $type, array $payload, OperationContext $context): array
    {
        $entryType = (string) ($payload['type'] ?? '');

        if (! in_array($entryType, TimeEntry::TYPES, true)) {
            throw new OperationRejected('invalid_payload', ['field' => 'type']);
        }

        try {
            $occurredAt = CarbonImmutable::parse((string) ($payload['occurredAt'] ?? ''));
        } catch (\Throwable) {
            $occurredAt = $context->clientTime;
        }

        $source = ($payload['source'] ?? 'app') === 'qr' ? 'qr' : 'app';

        if ($source === 'qr' && ! $this->qr->verify((string) ($payload['qrCode'] ?? ''), $occurredAt)) {
            throw new OperationRejected('invalid_clock_qr');
        }

        $entry = $this->recorder->record(
            user: $context->operator,
            type: $entryType,
            occurredAt: $occurredAt,
            source: $source,
            device: $context->device,
            uuid: (string) ($payload['entryUuid'] ?? $context->uuid),
            ip: request()->ip(),
            userAgent: request()->userAgent(),
        );

        $context->touch('time');

        return ['id' => $entry->id, 'syncedLate' => $entry->synced_late];
    }
}
