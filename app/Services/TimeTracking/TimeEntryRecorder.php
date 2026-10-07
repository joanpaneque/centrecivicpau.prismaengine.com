<?php

namespace App\Services\TimeTracking;

use App\Models\Device;
use App\Models\TimeEntry;
use App\Models\TimeEntryCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes the working-time record. Each user's entries form a hash chain so any tampering
 * at database level breaks verification (see verifyChain).
 */
class TimeEntryRecorder
{
    public const LATE_SYNC_SECONDS = 120;

    public function record(
        User $user,
        string $type,
        CarbonInterface $occurredAt,
        string $source,
        ?Device $device = null,
        ?string $uuid = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): TimeEntry {
        if (! in_array($type, TimeEntry::TYPES, true)) {
            throw new InvalidArgumentException("Invalid time entry type [$type].");
        }

        $uuid ??= (string) Str::uuid();

        return DB::transaction(function () use ($user, $type, $occurredAt, $source, $device, $uuid, $ip, $userAgent) {
            $existing = TimeEntry::query()->where('uuid', $uuid)->first();

            if ($existing) {
                return $existing;
            }

            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $previous = TimeEntry::query()->where('user_id', $user->id)->orderByDesc('id')->value('hash');
            $receivedAt = CarbonImmutable::now();
            $occurred = CarbonImmutable::instance($occurredAt)->min($receivedAt);

            $entry = new TimeEntry([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'type' => $type,
                'occurred_at' => $occurred,
                'received_at' => $receivedAt,
                'source' => $source,
                'device_id' => $device?->id,
                'synced_late' => $receivedAt->diffInSeconds($occurred, true) > self::LATE_SYNC_SECONDS,
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 250) : null,
                'previous_hash' => $previous,
            ]);
            $entry->hash = $this->hashEntry($entry);
            $entry->save();

            return $entry;
        });
    }

    /**
     * @param  array{action: string, time_entry_id?: int|null, new_type?: string|null, new_occurred_at?: CarbonInterface|null, reason: string}  $data
     */
    public function correct(User $worker, User $admin, array $data): TimeEntryCorrection
    {
        $entry = isset($data['time_entry_id']) ? TimeEntry::query()->where('user_id', $worker->id)->findOrFail($data['time_entry_id']) : null;

        $correction = new TimeEntryCorrection([
            'time_entry_id' => $entry?->id,
            'user_id' => $worker->id,
            'action' => $data['action'],
            'original_type' => $entry?->type,
            'original_occurred_at' => $entry?->occurred_at,
            'new_type' => $data['action'] === 'annul' ? null : ($data['new_type'] ?? $entry?->type),
            'new_occurred_at' => $data['action'] === 'annul' ? null : ($data['new_occurred_at'] ?? $entry?->occurred_at),
            'reason' => $data['reason'],
            'corrected_by' => $admin->id,
            'created_at' => now(),
        ]);

        $correction->hash = hash('sha256', implode('|', [
            $correction->time_entry_id, $correction->user_id, $correction->action,
            $correction->original_type, $correction->original_occurred_at?->toIso8601String(),
            $correction->new_type, $correction->new_occurred_at?->toIso8601String(),
            $correction->reason, $correction->corrected_by, $correction->created_at->toIso8601String(),
        ]));
        $correction->save();

        return $correction;
    }

    public function hashEntry(TimeEntry $entry): string
    {
        return hash('sha256', implode('|', [
            $entry->previous_hash ?? '',
            $entry->uuid,
            $entry->user_id,
            $entry->type,
            $entry->occurred_at->toIso8601String(),
            $entry->received_at->toIso8601String(),
            $entry->source,
            $entry->device_id ?? '',
        ]));
    }

    /**
     * @return array{valid: bool, checked: int, broken_at: int|null}
     */
    public function verifyChain(User $user): array
    {
        $previous = null;
        $checked = 0;

        foreach (TimeEntry::query()->where('user_id', $user->id)->orderBy('id')->cursor() as $entry) {
            $checked++;

            if ($entry->previous_hash !== $previous || ! hash_equals($this->hashEntry($entry), $entry->hash)) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => $entry->id];
            }

            $previous = $entry->hash;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
