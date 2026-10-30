<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Delivery note (albarà) of a customer: taken now, paid later. Pending while it has no
 * ticket; charged together with other notes through a normal order and ticket.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property int|null $order_id
 * @property int|null $device_id
 * @property int|null $created_by
 * @property Carbon $issued_at
 * @property string|null $table_label
 * @property list<array{name: array<string, string>, quantity: float, unit_price: int, vat_rate: float, discount_amount: int, total: int, modifiers: list<mixed>|null}> $lines
 * @property float $surcharge_rate
 * @property int $surcharge_amount
 * @property int $total
 * @property list<array{rate: float, base: int, vat: int, total: int}> $vat_breakdown
 * @property int|null $collect_order_id
 * @property int|null $ticket_id
 * @property Carbon|null $collected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer $customer
 * @property-read Ticket|null $ticket
 */
class DeliveryNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'collected_at' => 'datetime',
            'lines' => 'array',
            'vat_breakdown' => 'array',
            'surcharge_rate' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
