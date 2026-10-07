<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property array<string, string> $name
 * @property string $code
 * @property string $mode printer|screen|both
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductionDestination extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }

    public function usesPrinter(): bool
    {
        return in_array($this->mode, ['printer', 'both'], true);
    }

    public function usesScreen(): bool
    {
        return in_array($this->mode, ['screen', 'both'], true);
    }

    /**
     * @return BelongsToMany<Printer, $this>
     */
    public function printers(): BelongsToMany
    {
        return $this->belongsToMany(Printer::class);
    }
}
