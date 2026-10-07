<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only correction made by an administrator over the working-time record.
 *
 * @property int $id
 * @property int|null $time_entry_id
 * @property int $user_id
 * @property string $action modify|add|annul
 * @property string|null $original_type
 * @property Carbon|null $original_occurred_at
 * @property string|null $new_type
 * @property Carbon|null $new_occurred_at
 * @property string $reason
 * @property int $corrected_by
 * @property Carbon $created_at
 * @property string $hash
 * @property-read User $corrector
 */
class TimeEntryCorrection extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Las correcciones de fichaje son inmutables.'));
        static::deleting(fn () => throw new LogicException('Las correcciones de fichaje son inmutables.'));
    }

    protected function casts(): array
    {
        return [
            'original_occurred_at' => 'datetime',
            'new_occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * @return BelongsTo<TimeEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class, 'time_entry_id');
    }
}
