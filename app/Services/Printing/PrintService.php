<?php

namespace App\Services\Printing;

use App\Events\PrintJobsWaiting;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Sync\OperationContext;
use Illuminate\Support\Facades\DB;

class PrintService
{
    public const ORDER_NUMBER_TOKEN = '{#ORDER}';

    public function __construct(
        private readonly SimulatedPrinterDriver $simulated,
        private readonly EscPosPrinterDriver $escpos,
        private readonly SystemPrinterDriver $system,
    ) {}

    /**
     * @param  array<int, mixed>  $jobs
     * @return list<string> Names of the printers that received a job
     */
    public function storeMany(array $jobs, OperationContext $context, ?int $orderNumber = null): array
    {
        $printed = [];

        foreach ($jobs as $job) {
            if (! is_array($job) || ! is_string($job['uuid'] ?? null) || ! is_array($job['document'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $document */
            $document = $this->withOrderNumber($job['document'], $orderNumber);

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
     * The TPV writes the order number of the day as ORDER_NUMBER_TOKEN (only the server
     * knows it); a line with it and no number is left out.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function withOrderNumber(array $document, ?int $number): array
    {
        $lines = [];

        foreach ((array) ($document['lines'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $text = (string) json_encode([$line['text'] ?? null, $line['left'] ?? null, $line['right'] ?? null]);

            if (str_contains($text, self::ORDER_NUMBER_TOKEN)) {
                if ($number === null) {
                    continue;
                }

                foreach (['text', 'left', 'right'] as $key) {
                    if (is_string($line[$key] ?? null)) {
                        $line[$key] = str_replace(self::ORDER_NUMBER_TOKEN, (string) $number, $line[$key]);
                    }
                }
            }

            $lines[] = $line;
        }

        return ['lines' => $lines] + $document;
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

        // The Windows print program of the bar prints it: wake it up once the job is saved.
        if ($printer?->agent !== null && $printer->active) {
            $agent = $printer->agent;
            DB::afterCommit(fn () => PrintJobsWaiting::notify($agent));

            return $job;
        }

        try {
            $status = $this->driverFor($printer)->send($job, $printer);
        } catch (\Throwable $e) {
            report($e);
            $status = 'failed';
        }

        $job->forceFill(['status' => $status])->save();

        return $job;
    }

    private function driverFor(?Printer $printer): PrinterDriver
    {
        return match ($printer?->type) {
            'escpos_network' => $this->escpos,
            'system' => $this->system,
            default => $this->simulated,
        };
    }
}
