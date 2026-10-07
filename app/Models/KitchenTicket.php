<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int $production_destination_id
 * @property string|null $table_label
 * @property int|null $course
 * @property bool $held
 * @property string $status pending|preparing|ready|served
 * @property int|null $created_by
 * @property Carbon $sent_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $served_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Collection<int, KitchenTicketItem> $items
 * @property-read ProductionDestination $destination
 */
class KitchenTicket extends Model
{
    public const STATUSES = ['pending', 'preparing', 'ready', 'served'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'held' => 'boolean',
            'sent_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<KitchenTicketItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }

    /**
     * @return BelongsTo<ProductionDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(ProductionDestination::class, 'production_destination_id');
    }
}
