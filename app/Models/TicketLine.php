<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $order_line_id
 * @property array<string, string> $name
 * @property float $quantity
 * @property int $unit_price
 * @property float $vat_rate
 * @property int $discount_amount
 * @property int $total
 * @property list<array<string, mixed>>|null $modifiers
 */
class TicketLine extends Model
{
    use HasTranslations;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'modifiers' => 'array',
            'quantity' => 'float',
            'vat_rate' => 'float',
        ];
    }
}
