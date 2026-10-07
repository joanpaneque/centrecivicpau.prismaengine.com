<?php

namespace App\Services\TimeTracking;

use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Derives the working day from the immutable record: corrections are applied on top of
 * the original entries (latest correction wins), then entries are paired into work
 * sessions and compared with the planned shifts.
 */
class WorkedTime
{
    /**
     * Effective events for a user in a period, corrections applied.
     *
     * @return list<array{id: int|null, correction_id: int|null, type: string, at: CarbonImmutable, source: string, synced_late: bool, corrected: bool, original_at: CarbonImmutable|null, original_type: string|null}>
     */
    public function events(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $entries = TimeEntry::query()
            ->with('corrections')
            ->where('user_id', $user->id)
            ->whereBetween('occurred_at', [$from->copy()->subDay(), $to->copy()->addDay()])
            ->orderBy('occurred_at')
            ->get();

        $events = [];

        foreach ($entries as $entry) {
            $correction = $entry->corrections->last();

            if ($correction?->action === 'annul') {
                continue;
            }

            $at = CarbonImmutable::instance($correction->new_occurred_at ?? $entry->occurred_at);
            $events[] = [
                'id' => $entry->id,
                'correction_id' => $correction?->id,
                'type' => $correction->new_type ?? $entry->type,
                'at' => $at,
                'source' => $entry->source,
                'synced_late' => $entry->synced_late,
                'corrected' => $correction !== null,
                'original_at' => $correction ? CarbonImmutable::instance($entry->occurred_at) : null,
                'original_type' => $correction ? $entry->type : null,
            ];
        }

        $added = TimeEntryCorrection::query()
            ->where('user_id', $user->id)
            ->where('action', 'add')
            ->whereBetween('new_occurred_at', [$from->copy()->subDay(), $to->copy()->addDay()])
            ->get();

        foreach ($added as $correction) {
            if ($correction->new_occurred_at === null || $correction->new_type === null) {
                continue;
            }

            $events[] = [
                'id' => null,
                'correction_id' => $correction->id,
                'type' => $correction->new_type,
                'at' => CarbonImmutable::instance($correction->new_occurred_at),
                'source' => 'admin',
                'synced_late' => false,
                'corrected' => true,
                'original_at' => null,
                'original_type' => null,
            ];
        }

        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);

        return array_values(array_filter($events, fn ($e) => $e['at']->betweenIncluded($from->copy()->subHours(14), $to)));
    }

    /**
     * Pair events into sessions (clock in → clock out) with their breaks.
     *
     * @param  list<array{type: string, at: CarbonImmutable}>  $events
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable|null, break_minutes: int, worked_minutes: int}>
     */
    public function sessions(array $events, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $sessions = [];
        $current = null;
        $breakStart = null;

        foreach ($events as $event) {
            switch ($event['type']) {
                case 'clock_in':
                    if ($current !== null) {
                        $sessions[] = $this->finish($current, null, $now);
                    }
                    $current = ['start' => $event['at'], 'break' => 0];
                    $breakStart = null;
                    break;
                case 'break_start':
                    if ($current !== null && $breakStart === null) {
                        $breakStart = $event['at'];
                    }
                    break;
                case 'break_end':
                    if ($current !== null && $breakStart !== null) {
                        $current['break'] += (int) $breakStart->diffInMinutes($event['at'], true);
                        $breakStart = null;
                    }
                    break;
                case 'clock_out':
                    if ($current !== null) {
                        if ($breakStart !== null) {
                            $current['break'] += (int) $breakStart->diffInMinutes($event['at'], true);
                            $breakStart = null;
                        }
                        $sessions[] = $this->finish($current, $event['at'], $now);
                        $current = null;
                    }
                    break;
            }
        }

        if ($current !== null) {
            if ($breakStart !== null) {
                $current['break'] += (int) $breakStart->diffInMinutes($now, true);
            }
            $sessions[] = $this->finish($current, null, $now);
        }

        return $sessions;
    }

    /**
     * @param  array{start: CarbonImmutable, break: int}  $current
     * @return array{start: CarbonImmutable, end: CarbonImmutable|null, break_minutes: int, worked_minutes: int}
     */
    private function finish(array $current, ?CarbonImmutable $end, CarbonImmutable $now): array
    {
        $until = $end ?? $now;

        return [
            'start' => $current['start'],
            'end' => $end,
            'break_minutes' => $current['break'],
            'worked_minutes' => max(0, (int) $current['start']->diffInMinutes($until, true) - $current['break']),
        ];
    }

    /**
     * Per-day report for a worker: worked vs planned, overtime and alerts.
     *
     * @return array{days: list<array<string, mixed>>, totals: array{worked: int, planned: int, overtime: int, breaks: int}, alerts: list<array<string, mixed>>}
     */
    public function report(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        $events = $this->events($user, $from, $to);
        $sessions = $this->sessions($events);
        $shifts = Shift::query()->where('user_id', $user->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        $reminder = (int) AppSettings::get('clock_out_reminder_minutes', 120);
        $tolerance = (int) AppSettings::get('off_shift_tolerance_minutes', 30);

        $days = [];
        $alerts = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $daySessions = array_values(array_filter($sessions, fn ($s) => $s['start']->toDateString() === $date));
            $dayShifts = $shifts->filter(fn (Shift $s) => $s->date->toDateString() === $date);
            $worked = array_sum(array_column($daySessions, 'worked_minutes'));
            $breaks = array_sum(array_column($daySessions, 'break_minutes'));
            $planned = (int) $dayShifts->sum(fn (Shift $s) => $s->plannedMinutes());

            foreach ($daySessions as $session) {
                if ($session['end'] === null) {
                    $shiftEnd = $dayShifts->map(fn (Shift $s) => $s->endsAt())->max();
                    $limit = $shiftEnd ? CarbonImmutable::instance($shiftEnd)->addMinutes($reminder) : $session['start']->addHours(12);

                    if (CarbonImmutable::now()->gt($limit)) {
                        $alerts[] = ['type' => 'missing_clock_out', 'date' => $date, 'at' => $session['start']->toIso8601String()];
                    }
                }

                $inShift = $dayShifts->contains(fn (Shift $s) => $session['start']->betweenIncluded(
                    CarbonImmutable::instance($s->startsAt())->subMinutes($tolerance),
                    CarbonImmutable::instance($s->endsAt())->addMinutes($tolerance),
                ));

                if (! $inShift) {
                    $alerts[] = ['type' => 'off_shift', 'date' => $date, 'at' => $session['start']->toIso8601String()];
                }
            }

            if ($daySessions === [] && $planned === 0) {
                continue;
            }

            $days[] = [
                'date' => $date,
                'sessions' => array_map(fn ($s) => [
                    'start' => $s['start']->toIso8601String(),
                    'end' => $s['end']?->toIso8601String(),
                    'break_minutes' => $s['break_minutes'],
                    'worked_minutes' => $s['worked_minutes'],
                ], $daySessions),
                'worked_minutes' => $worked,
                'break_minutes' => $breaks,
                'planned_minutes' => $planned,
                'overtime_minutes' => $planned > 0 ? max(0, $worked - $planned) : 0,
                'shifts' => $dayShifts->map(fn (Shift $s) => substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5))->values()->all(),
            ];
        }

        return [
            'days' => $days,
            'totals' => [
                'worked' => (int) array_sum(array_column($days, 'worked_minutes')),
                'planned' => (int) array_sum(array_column($days, 'planned_minutes')),
                'overtime' => (int) array_sum(array_column($days, 'overtime_minutes')),
                'breaks' => (int) array_sum(array_column($days, 'break_minutes')),
            ],
            'alerts' => $alerts,
        ];
    }

    /**
     * Current state shown on the big clock-in button.
     *
     * @return array{state: string, since: string|null, todayMinutes: int, entries: list<array{type: string, at: string, syncedLate: bool, corrected: bool}>}
     */
    public function clockState(User $user): array
    {
        $now = CarbonImmutable::now();
        $events = $this->events($user, $now->startOfDay()->subHours(12), $now);
        $state = 'out';
        $since = null;

        foreach ($events as $event) {
            $state = match ($event['type']) {
                'clock_in', 'break_end' => 'in',
                'break_start' => 'break',
                default => 'out',
            };
            $since = $event['at']->toIso8601String();
        }

        $today = array_values(array_filter($events, fn ($e) => $e['at']->isSameDay($now)));
        $sessions = $this->sessions(array_values(array_filter($events, fn ($e) => $e['at']->gte($now->startOfDay()) || $e['type'] !== 'clock_out')));

        return [
            'state' => $state,
            'since' => $since,
            'todayMinutes' => (int) array_sum(array_map(
                fn ($s) => $s['start']->isSameDay($now) || $s['end'] === null ? $s['worked_minutes'] : 0,
                $sessions,
            )),
            'entries' => array_map(fn ($e) => [
                'type' => $e['type'],
                'at' => $e['at']->toIso8601String(),
                'syncedLate' => $e['synced_late'],
                'corrected' => $e['corrected'],
            ], $today),
        ];
    }
}
