<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dish of a carta. With a product of the catalog and no name or price of its own, it
 * shows the product's (and follows it when the product changes).
 *
 * @property int $id
 * @property int $carta_section_id
 * @property int|null $product_id
 * @property array<string, string>|null $name
 * @property array<string, string>|null $description
 * @property int|null $price cents; null = the product's price
 * @property string|null $price_text shown instead of a price ("S.M." = según mercado)
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product|null $product
 */
class CartaItem extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $touches = ['section'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CartaSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CartaSection::class, 'carta_section_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    public function displayName(): array
    {
        return $this->name ?? $this->product->name ?? ['ca' => '', 'es' => ''];
    }

    public function effectivePrice(): ?int
    {
        return $this->price ?? $this->product?->price;
    }
}
