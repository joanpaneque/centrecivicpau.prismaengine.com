<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $modifier_group_id
 * @property array<string, string> $name
 * @property int $price_delta
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Modifier extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $touches = ['group'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
