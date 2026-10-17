<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $carta_id
 * @property array<string, string> $name
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CartaSection extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $touches = ['carta'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Carta, $this>
     */
    public function carta(): BelongsTo
    {
        return $this->belongsTo(Carta::class);
    }

    /**
     * @return HasMany<CartaItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartaItem::class)->orderBy('sort');
    }
}
