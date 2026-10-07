<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TimeTracking\TimeReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only remote access to the working-time record for the Labour Inspectorate
 * (art. 34.9 ET: the record must be available to workers, their representatives and
 * the Inspection). Every access is audited.
 */
class InspectionController extends Controller
{
    public function __construct(private readonly TimeReportExporter $exporter) {}

    public function workers(Request $request): JsonResponse
    {
        $this->audit($request, 'workers');

        return response()->json([
            'data' => User::query()->whereHas('timeEntries')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'tax_id' => $u->tax_id,
                'active' => $u->active,
            ]),
        ]);
    }

    public function records(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'worker' => ['nullable', 'integer', 'exists:users,id'],
            'format' => ['nullable', 'in:json,pdf,csv,xlsx'],
        ]);

        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay()->min($from->addYear());
        $users = User::query()->when($data['worker'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->whereHas('timeEntries'))->orderBy('name')->get();
        $format = $data['format'] ?? 'json';

        $this->audit($request, 'records', $data);

        if ($format === 'pdf') {
            return response($this->exporter->pdf($users, $from, $to), 200, ['Content-Type' => 'application/pdf']);
        }

        if ($format !== 'json') {
            return response($this->exporter->spreadsheet($users, $from, $to, $format), 200, [
                'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="registro-jornada.'.$format.'"',
            ]);
        }

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data' => array_map(fn (array $row) => [
                'worker' => ['id' => $row['user']->id, 'name' => $row['user']->name, 'tax_id' => $row['user']->tax_id],
                'integrity' => $row['chain'],
                'totals_minutes' => $row['report']['totals'],
                'days' => $row['report']['days'],
                'entries' => $row['entries']->map(fn ($e) => [
                    'id' => $e->id,
                    'type' => $e->type,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                    'received_at' => $e->received_at->toIso8601String(),
                    'source' => $e->source,
                    'synced_late' => $e->synced_late,
                    'hash' => $e->hash,
                    'previous_hash' => $e->previous_hash,
                ])->values()->all(),
                'corrections' => $row['corrections']->map(fn ($c) => [
                    'id' => $c->id,
                    'time_entry_id' => $c->time_entry_id,
                    'action' => $c->action,
                    'original_type' => $c->original_type,
                    'original_occurred_at' => $c->original_occurred_at?->toIso8601String(),
                    'new_type' => $c->new_type,
                    'new_occurred_at' => $c->new_occurred_at?->toIso8601String(),
                    'reason' => $c->reason,
                    'corrected_by' => $c->corrector->name,
                    'created_at' => $c->created_at->toIso8601String(),
                    'hash' => $c->hash,
                ])->values()->all(),
            ], $this->exporter->data($users, $from, $to)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function audit(Request $request, string $endpoint, array $params = []): void
    {
        AuditLog::record('inspection.access', null, ['endpoint' => $endpoint, 'params' => $params, 'ip' => $request->ip(), 'token' => $request->user()?->currentAccessToken()?->getKey()]);
    }
}
