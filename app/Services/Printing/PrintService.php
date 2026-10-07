<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Sync\OperationContext;

class PrintService
{
    public function __construct(private readonly PrinterDriver $driver) {}

    /**
     * @param  array<int, mixed>  $jobs
     * @return list<string> Names of the printers that received a job
     */
    public function storeMany(array $jobs, OperationContext $context): array
    {
        $printed = [];

        foreach ($jobs as $job) {
            if (! is_array($job) || ! is_string($job['uuid'] ?? null) || ! is_array($job['document'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $document */
            $document = $job['document'];

            $stored = $this->store(
                uuid: $job['uuid'],
                kind: (string) ($job['kind'] ?? 'order'),
                title: (string) ($job['title'] ?? ''),
                document: $document,
                printerId: is_numeric($job['printerId'] ?? null) ? (int) $job['printerId'] : null,
                userId: $context->operator->id,
                deviceId: $context->device?->id,
            );

            $printed[] = (string) $stored->printer_name;
        }

        return $printed;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function store(string $uuid, string $kind, string $title, array $document, ?int $printerId, ?int $userId, ?int $deviceId): PrintJob
    {
        $existing = PrintJob::query()->where('uuid', $uuid)->first();

        if ($existing) {
            return $existing;
        }

        $printer = $printerId ? Printer::query()->find($printerId) : null;

        $job = PrintJob::query()->create([
            'uuid' => $uuid,
            'printer_id' => $printer?->id,
            'printer_name' => $printer->name ?? __('tpv.screen_only'),
            'kind' => $kind,
            'title' => mb_substr($title, 0, 250),
            'document' => $document,
            'status' => 'pending',
            'created_by' => $userId,
            'device_id' => $deviceId,
        ]);

        try {
            $status = $this->driver->send($job, $printer);
        } catch (\Throwable $e) {
            report($e);
            $status = 'failed';
        }

        $job->forceFill(['status' => $status])->save();

        return $job;
    }
}
