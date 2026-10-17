<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A printable carta (drinks, tapas, desserts…), separate from the set menus.
 *
 * @property int $id
 * @property array<string, string> $name
 * @property array<string, string>|null $subtitle
 * @property string $template classic|halloween|christmas|easter|kids (printed design)
 * @property array{hash?: string, locales?: array<string, array<string, mixed>>}|null $translations texts in the printing languages other than ca/es
 * @property bool $active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Carta extends Model
{
    use HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'subtitle' => 'array',
            'translations' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CartaSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(CartaSection::class)->orderBy('sort');
    }
}
