<?php

namespace App\Models;

use App\Enums\DeviceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property DeviceType $type
 * @property string $token_hash
 * @property int|null $registered_by
 * @property Carbon|null $last_seen_at
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TicketSeries|null $ticketSeries
 */
class Device extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'type' => DeviceType::class,
            'last_seen_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasOne<TicketSeries, $this>
     */
    public function ticketSeries(): HasOne
    {
        return $this->hasOne(TicketSeries::class)->where('kind', 'simplified');
    }

    public function isCashier(): bool
    {
        return $this->type === DeviceType::Cashier;
    }
}
