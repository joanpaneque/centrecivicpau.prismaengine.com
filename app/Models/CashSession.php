<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $device_id
 * @property int|null $opened_by
 * @property Carbon $opened_at
 * @property int $opening_float
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property array<string, int>|null $cash_count
 * @property int|null $counted_cash
 * @property int|null $expected_cash
 * @property int|null $difference
 * @property array<string, mixed>|null $summary
 * @property int|null $z_number
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Device|null $device
 * @property-read User|null $opener
 * @property-read User|null $closer
 */
class CashSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'cash_count' => 'array',
            'summary' => 'array',
        ];
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
