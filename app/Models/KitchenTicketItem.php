<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $kitchen_ticket_id
 * @property int|null $order_line_id
 * @property array<string, string> $name
 * @property int $quantity
 * @property list<array<string, mixed>>|null $modifiers
 * @property string|null $note
 * @property bool $voided
 */
class KitchenTicketItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'modifiers' => 'array',
            'voided' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<KitchenTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }
}
