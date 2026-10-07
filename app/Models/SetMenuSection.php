<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $set_menu_id
 * @property array<string, string> $name
 * @property int $choices
 * @property int|null $course
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SetMenuSection extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $touches = ['menu'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SetMenu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(SetMenu::class, 'set_menu_id');
    }

    /**
     * @return HasMany<SetMenuSectionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SetMenuSectionItem::class)->orderBy('sort');
    }
}
