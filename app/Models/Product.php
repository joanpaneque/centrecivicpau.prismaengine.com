<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $category_id
 * @property array<string, string> $name
 * @property int $price
 * @property float $vat_rate
 * @property string|null $photo_path
 * @property string|null $color
 * @property list<string>|null $allergens
 * @property int|null $production_destination_id
 * @property bool $active
 * @property bool $sold_out
 * @property int $sort
 * @property int $order_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category $category
 */
class Product extends Model
{
    use HasTranslations, SoftDeletes;

    /**
     * The 14 allergens of EU Regulation 1169/2011, Annex II.
     */
    public const ALLERGENS = [
        'gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk',
        'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'allergens' => 'array',
            'vat_rate' => 'float',
            'active' => 'boolean',
            'sold_out' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /**
     * @return BelongsToMany<ModifierGroup, $this>
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function effectiveDestinationId(): ?int
    {
        return $this->production_destination_id ?? $this->category->effectiveDestinationId();
    }
}
