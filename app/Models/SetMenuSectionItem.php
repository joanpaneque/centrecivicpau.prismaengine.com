<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $set_menu_section_id
 * @property int|null $product_id
 * @property array<string, string>|null $name
 * @property int|null $production_destination_id
 * @property int $supplement
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product|null $product
 */
class SetMenuSectionItem extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $touches = ['section'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SetMenuSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(SetMenuSection::class, 'set_menu_section_id');
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
        if ($this->name !== null) {
            return $this->name;
        }

        return $this->product_id !== null ? $this->product->name : ['ca' => '', 'es' => ''];
    }

    public function effectiveDestinationId(): ?int
    {
        return $this->production_destination_id ?? $this->product?->effectiveDestinationId();
    }
}
