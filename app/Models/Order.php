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
 * @property int|null $number order number of the day, printed on the kitchen tickets
 * @property int|null $dining_table_id
 * @property string $status open|bill_requested|paid|cancelled|merged
 * @property int|null $guests
 * @property string|null $label
 * @property int|null $opened_by
 * @property Carbon $opened_at
 * @property Carbon|null $bill_requested_at
 * @property Carbon|null $proforma_printed_at
 * @property Carbon|null $closed_at
 * @property int|null $merged_into_id
 * @property string|null $reservation_uuid
 * @property int|null $customer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DiningTable|null $table
 * @property-read Customer|null $customer
 * @property-read Collection<int, OrderLine> $lines
 */
class Order extends Model
{
    public const ACTIVE_STATUSES = ['open', 'bill_requested'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'bill_requested_at' => 'datetime',
            'proforma_printed_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Delivery notes this order charges (an order made to collect them).
     *
     * @return HasMany<DeliveryNote, $this>
     */
    public function collectedNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class, 'collect_order_id');
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
