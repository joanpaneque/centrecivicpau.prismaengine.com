<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable working-time record (art. 34.9 ET). Rows can only be inserted.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $type clock_in|clock_out|break_start|break_end
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property string $source app|qr|admin
 * @property int|null $device_id
 * @property bool $synced_late
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $hash
 * @property string|null $previous_hash
 * @property-read User $user
 * @property-read Device|null $device
 * @property-read Collection<int, TimeEntryCorrection> $corrections
 */
class TimeEntry extends Model
{
    public const TYPES = ['clock_in', 'clock_out', 'break_start', 'break_end'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los fichajes no se pueden modificar: usa una corrección.'));
        static::deleting(fn () => throw new LogicException('Los fichajes no se pueden borrar.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'synced_late' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return HasMany<TimeEntryCorrection, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(TimeEntryCorrection::class)->orderBy('id');
    }
}
