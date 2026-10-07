<?php

namespace App\Http\Controllers\Admin;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use App\Services\TimeTracking\TimeEntryRecorder;
use App\Services\TimeTracking\TimeReportExporter;
use App\Services\TimeTracking\WorkedTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TimeTrackingController extends Controller
{
    public function __construct(
        private readonly WorkedTime $worked,
        private readonly TimeEntryRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $workers = User::query()->orderByDesc('active')->orderBy('name')->get(['id', 'name', 'active', 'tax_id', 'role']);
        $userId = $request->integer('user') ?: null;
        $selected = $userId ? $workers->firstWhere('id', $userId) : null;

        $summary = $workers->where('active', true)->map(function (User $user) use ($from, $to) {
            $report = $this->worked->report($user, $from, $to);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'totals' => $report['totals'],
                'alerts' => count($report['alerts']),
            ];
        })->values();

        $detail = null;

        if ($selected instanceof User) {
            $report = $this->worked->report($selected, $from, $to);
            $entries = TimeEntry::query()->with(['corrections.corrector:id,name', 'device:id,name'])
                ->where('user_id', $selected->id)
                ->whereBetween('occurred_at', [$from->startOfDay()->subDay(), $to->endOfDay()])
                ->orderBy('occurred_at')->get();

            $detail = [
                'user' => ['id' => $selected->id, 'name' => $selected->name, 'taxId' => $selected->tax_id],
                'report' => $report,
                'entries' => $entries->map(fn (TimeEntry $e) => [
                    'id' => $e->id,
                    'type' => $e->type,
                    'occurredAt' => $e->occurred_at->toIso8601String(),
                    'receivedAt' => $e->received_at->toIso8601String(),
                    'source' => $e->source,
                    'device' => $e->device?->name,
                    'syncedLate' => $e->synced_late,
                    'hash' => $e->hash,
                    'corrections' => $e->corrections->map(fn (TimeEntryCorrection $c) => [
                        'id' => $c->id,
                        'action' => $c->action,
                        'newType' => $c->new_type,
                        'newOccurredAt' => $c->new_occurred_at?->toIso8601String(),
                        'reason' => $c->reason,
                        'by' => $c->corrector->name,
                        'createdAt' => $c->created_at->toIso8601String(),
                    ])->values()->all(),
                ])->values()->all(),
                'added' => TimeEntryCorrection::query()->with('corrector:id,name')->where('user_id', $selected->id)->where('action', 'add')
                    ->whereBetween('new_occurred_at', [$from->startOfDay(), $to->endOfDay()])->orderBy('new_occurred_at')->get()
                    ->map(fn (TimeEntryCorrection $c) => [
                        'id' => $c->id,
                        'newType' => $c->new_type,
                        'newOccurredAt' => $c->new_occurred_at?->toIso8601String(),
                        'reason' => $c->reason,
                        'by' => $c->corrector->name,
                        'createdAt' => $c->created_at->toIso8601String(),
                    ])->values(),
                'chain' => $this->recorder->verifyChain($selected),
            ];
        }

        return Inertia::render('admin/TimeTracking', [
            'workers' => $workers->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'active' => $u->active])->values(),
            'summary' => $summary,
            'detail' => $detail,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'user' => $userId],
        ]);
    }

    public function correct(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['modify', 'add', 'annul'])],
            'time_entry_id' => ['nullable', 'required_unless:action,add', 'integer', Rule::exists('time_entries', 'id')->where('user_id', $user->id)],
            'new_type' => ['nullable', 'required_if:action,add', Rule::in(TimeEntry::TYPES)],
            'new_occurred_at' => ['nullable', 'required_unless:action,annul', 'date', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        $correction = $this->recorder->correct($user, $admin, [
            'action' => $data['action'],
            'time_entry_id' => $data['time_entry_id'] ?? null,
            'new_type' => $data['new_type'] ?? null,
            'new_occurred_at' => isset($data['new_occurred_at']) ? CarbonImmutable::parse($data['new_occurred_at']) : null,
            'reason' => $data['reason'],
        ]);

        AuditLog::record('time.correct', $correction, ['action' => $data['action'], 'worker' => $user->id], $data['reason'], $admin->id);
        TpvChanged::notify(['time']);
        $this->toast(__('tpv.correction_saved'));

        return back();
    }

    public function export(Request $request, TimeReportExporter $exporter): HttpResponse
    {
        [$from, $to] = $this->period($request);
        $format = $request->validate(['format' => ['required', Rule::in(['pdf', 'csv', 'xlsx'])]])['format'];
        $users = $this->users($request);
        $name = 'registre-jornada-'.$from->toDateString().'_'.$to->toDateString();

        AuditLog::record('time.export', null, ['format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'users' => $users->pluck('id')], userId: $request->user()?->id);

        if ($format === 'pdf') {
            return response($exporter->pdf($users, $from, $to), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
            ]);
        }

        return response($exporter->spreadsheet($users, $from, $to, $format), 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'.'.$format.'"',
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function users(Request $request): Collection
    {
        $id = $request->integer('user');

        return User::query()
            ->when($id, fn ($q) => $q->whereKey($id), fn ($q) => $q->whereHas('timeEntries'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now()->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();

        return [$from, $to->min($from->addYear())];
    }
}
