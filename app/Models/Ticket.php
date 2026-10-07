<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $ticket_series_id
 * @property int $number
 * @property string $full_number
 * @property int|null $order_id
 * @property int|null $cash_session_id
 * @property int|null $device_id
 * @property string|null $table_label
 * @property int|null $waiter_id
 * @property int|null $cashier_id
 * @property Carbon $issued_at
 * @property int $subtotal
 * @property float $surcharge_rate
 * @property int $surcharge_amount
 * @property int $discount_total
 * @property int $total
 * @property list<array{rate: float, base: int, vat: int, total: int}> $vat_breakdown
 * @property array<string, string> $issuer
 * @property string $public_token
 * @property string|null $hash
 * @property string|null $previous_hash
 * @property array<string, mixed>|null $verifactu
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TicketSeries $series
 * @property-read Collection<int, TicketLine> $lines
 * @property-read Collection<int, Payment> $payments
 * @property-read Invoice|null $invoice
 * @property-read User|null $waiter
 * @property-read User|null $cashier
 */
class Ticket extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'surcharge_rate' => 'float',
            'vat_breakdown' => 'array',
            'issuer' => 'array',
            'verifactu' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TicketSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'ticket_series_id');
    }

    /**
     * @return HasMany<TicketLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TicketLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}
