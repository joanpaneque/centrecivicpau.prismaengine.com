<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $ticket_id null for an invoice of a customer summary (several tickets)
 * @property int|null $customer_statement_id
 * @property int $ticket_series_id
 * @property int $number
 * @property string $full_number
 * @property string $customer_name
 * @property string $customer_tax_id
 * @property string $customer_address
 * @property string|null $customer_email
 * @property Carbon $issued_at
 * @property string|null $pdf_path
 * @property Carbon|null $emailed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ticket|null $ticket
 * @property-read CustomerStatement|null $statement
 */
class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<CustomerStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(CustomerStatement::class, 'customer_statement_id');
    }

    /**
     * The tickets this invoice replaces: its own, or all those of the summary.
     *
     * @return Collection<int, Ticket>
     */
    public function tickets(): Collection
    {
        if ($this->statement) {
            return $this->statement->tickets;
        }

        return new Collection($this->ticket ? [$this->ticket] : []);
    }
}
