<?php

use App\Models\AuditLog;
use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use App\Services\TimeTracking\TimeEntryRecorder;
use App\Services\TimeTracking\WorkedTime;
use Carbon\CarbonImmutable;

function clockDay(User $user, string $in, string $out): array
{
    $recorder = app(TimeEntryRecorder::class);

    return [
        $recorder->record($user, 'clock_in', CarbonImmutable::parse($in), 'app'),
        $recorder->record($user, 'clock_out', CarbonImmutable::parse($out), 'app'),
    ];
}

test('time entries cannot be modified or deleted', function () {
    $user = User::factory()->create();
    [$in] = clockDay($user, 'yesterday 09:00', 'yesterday 13:00');

    expect(fn () => $in->forceFill(['occurred_at' => now()])->save())->toThrow(LogicException::class)
        ->and(fn () => $in->delete())->toThrow(LogicException::class);
});

test('entries are hash chained per worker', function () {
    $user = User::factory()->create();
    [$in, $out] = clockDay($user, 'yesterday 09:00', 'yesterday 13:00');

    expect($out->previous_hash)->toBe($in->hash)
        ->and(app(TimeEntryRecorder::class)->verifyChain($user))->toMatchArray(['valid' => true, 'checked' => 2]);
});

test('recording the same entry uuid twice is idempotent', function () {
    $user = User::factory()->create();
    $recorder = app(TimeEntryRecorder::class);
    $recorder->record($user, 'clock_in', now()->subHour(), 'app', uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $recorder->record($user, 'clock_in', now()->subHour(), 'app', uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

    expect(TimeEntry::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('worked minutes are computed from the clock events', function () {
    $user = User::factory()->create();
    clockDay($user, 'yesterday 09:00', 'yesterday 13:30');

    $report = app(WorkedTime::class)->report($user, CarbonImmutable::parse('yesterday'), CarbonImmutable::parse('yesterday'));

    expect($report['totals']['worked'])->toBe(270);
});

test('admins correct entries without touching the original record', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    [, $out] = clockDay($user, 'yesterday 09:00', 'yesterday 13:00');
    $original = $out->occurred_at->toIso8601String();

    $this->actingAs($admin)->post(route('admin.time.correct', $user), [
        'action' => 'modify',
        'time_entry_id' => $out->id,
        'new_occurred_at' => CarbonImmutable::parse('yesterday 14:00')->toIso8601String(),
        'reason' => 'Es va oblidar de fitxar la sortida',
    ])->assertRedirect();

    $correction = TimeEntryCorrection::query()->where('time_entry_id', $out->id)->firstOrFail();

    expect($correction->corrected_by)->toBe($admin->id)
        ->and($correction->hash)->toHaveLength(64)
        ->and($out->fresh()?->occurred_at->toIso8601String())->toBe($original);

    $report = app(WorkedTime::class)->report($user, CarbonImmutable::parse('yesterday'), CarbonImmutable::parse('yesterday'));

    expect($report['totals']['worked'])->toBe(300);
});

test('corrections require a reason', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    [$in] = clockDay($user, 'yesterday 09:00', 'yesterday 13:00');

    $this->actingAs($admin)->post(route('admin.time.correct', $user), [
        'action' => 'annul',
        'time_entry_id' => $in->id,
        'reason' => '',
    ])->assertSessionHasErrors('reason');

    expect(TimeEntryCorrection::query()->count())->toBe(0);
});

test('time exports are available as csv and audited', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Treballadora Prova']);
    clockDay($user, 'yesterday 09:00', 'yesterday 13:00');

    $response = $this->actingAs($admin)->get(route('admin.time.export', [
        'format' => 'csv',
        'from' => CarbonImmutable::parse('yesterday')->toDateString(),
        'to' => CarbonImmutable::parse('today')->toDateString(),
    ]))->assertOk();

    expect((string) $response->getContent())->toContain('Treballadora Prova')
        ->and(AuditLog::query()->where('action', 'time.export')->exists())->toBeTrue();
});
