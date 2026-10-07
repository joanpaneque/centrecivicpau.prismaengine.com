<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $phone
 * @property int $party_size
 * @property Carbon $reserved_at
 * @property int $duration_minutes
 * @property int|null $zone_id
 * @property int|null $dining_table_id
 * @property string|null $notes
 * @property string $status confirmed|seated|no_show|cancelled
 * @property string $source staff|web|api
 * @property int|null $created_by
 * @property string|null $order_uuid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read DiningTable|null $table
 * @property-read Zone|null $zone
 */
class Reservation extends Model
{
    use SoftDeletes;

    public const STATUSES = ['confirmed', 'seated', 'completed', 'no_show', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class)->withTrashed();
    }
}
