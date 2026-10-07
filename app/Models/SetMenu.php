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
 * @property array<string, string>|null $includes
 * @property int $price
 * @property float $vat_rate
 * @property string|null $color
 * @property string $schedule_type always|weekdays|dates
 * @property list<int>|null $weekdays ISO weekdays, 1 = Monday
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property bool $active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class SetMenu extends Model
{
    use HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'includes' => 'array',
            'weekdays' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'vat_rate' => 'float',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SetMenuSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(SetMenuSection::class)->orderBy('sort');
    }

    public function isAvailableOn(Carbon $date): bool
    {
        if (! $this->active) {
            return false;
        }

        return match ($this->schedule_type) {
            'weekdays' => in_array($date->isoWeekday(), $this->weekdays ?? [], true),
            'dates' => ($this->starts_on === null || $date->greaterThanOrEqualTo($this->starts_on->startOfDay()))
                && ($this->ends_on === null || $date->lessThanOrEqualTo($this->ends_on->endOfDay())),
            default => true,
        };
    }
}
