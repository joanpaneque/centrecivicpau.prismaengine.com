<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property array<string, string> $name
 * @property string $slug
 * @property bool $applies_terrace_surcharge
 * @property bool $is_bar
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Zone extends Model
{
    use HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'applies_terrace_surcharge' => 'boolean',
            'is_bar' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DiningTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class)->orderBy('sort');
    }
}
