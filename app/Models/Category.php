<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property array<string, string> $name
 * @property string|null $color
 * @property int|null $production_destination_id
 * @property bool $is_menu
 * @property bool $active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category|null $parent
 * @property-read ProductionDestination|null $destination
 */
class Category extends Model
{
    use HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'is_menu' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort');
    }

    /**
     * @return BelongsTo<ProductionDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(ProductionDestination::class, 'production_destination_id');
    }

    /**
     * @return BelongsToMany<ModifierGroup, $this>
     */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class);
    }

    /**
     * Destination applied to products of this category, inherited from the parent when missing.
     */
    public function effectiveDestinationId(): ?int
    {
        return $this->production_destination_id ?? $this->parent?->effectiveDestinationId();
    }

    public function effectiveColor(): ?string
    {
        return $this->color ?? $this->parent?->effectiveColor();
    }
}
