<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $type simulated|escpos_network
 * @property string|null $ip
 * @property int|null $port
 * @property string|null $model
 * @property int $paper_width characters per line
 * @property bool $is_ticket_printer
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Printer extends Model
{
    public const TYPES = ['simulated', 'escpos_network'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_ticket_printer' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<ProductionDestination, $this>
     */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(ProductionDestination::class);
    }
}
