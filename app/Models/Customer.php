<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A regular customer with a code chosen by the staff. Fiscal data is optional: it is only
 * needed (and asked on the invoice page) when they want a full invoice.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $tax_id
 * @property string|null $address
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Ticket> $tickets
 * @property-read Collection<int, CustomerStatement> $statements
 */
class Customer extends Model
{
    protected $guarded = ['id'];

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<CustomerStatement, $this>
     */
    public function statements(): HasMany
    {
        return $this->hasMany(CustomerStatement::class);
    }

    /**
     * Its tickets not yet in a summary nor invoiced on their own: what the next summary takes.
     *
     * @return HasMany<Ticket, $this>
     */
    public function pendingTickets(): HasMany
    {
        return $this->tickets()->whereNull('customer_statement_id')->whereDoesntHave('invoice');
    }
}
