<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int|null $parent_line_id
 * @property int|null $product_id
 * @property int|null $set_menu_id
 * @property int|null $production_destination_id
 * @property array<string, string> $name
 * @property int $quantity
 * @property int $unit_price
 * @property float $vat_rate
 * @property list<array{id: int|null, name: array<string, string>, price_delta: int}>|null $modifiers
 * @property string|null $note
 * @property int|null $course
 * @property string|null $discount_type percent|amount
 * @property int $discount_value percent * 100 or cents
 * @property string|null $discount_reason
 * @property int|null $discounted_by
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property int $paid_quantity
 * @property int|null $created_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 * @property-read Collection<int, OrderLine> $children
 */
class OrderLine extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $touches = ['order'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'modifiers' => 'array',
            'vat_rate' => 'float',
            'voided_at' => 'datetime',
            'sent_at' => 'datetime',
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
     * @return HasMany<OrderLine, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'parent_line_id');
    }

    public function unitTotal(): int
    {
        $modifiers = array_sum(array_map(fn (array $m): int => (int) $m['price_delta'], $this->modifiers ?? []));

        return $this->unit_price + $modifiers;
    }

    public function grossTotal(): int
    {
        return $this->unitTotal() * $this->quantity;
    }

    public function discountAmount(): int
    {
        $gross = $this->grossTotal();

        return match ($this->discount_type) {
            'percent' => (int) round($gross * $this->discount_value / 10000),
            'amount' => min($gross, $this->discount_value),
            default => 0,
        };
    }

    public function netTotal(): int
    {
        return $this->voided_at ? 0 : $this->grossTotal() - $this->discountAmount();
    }
}
