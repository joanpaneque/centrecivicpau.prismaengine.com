<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Decorative element of the floor plan (bar counter, wall, door, column, plant...). It has
 * no behaviour in the POS; it only helps staff recognise the room.
 *
 * @property int $id
 * @property int $zone_id
 * @property string $type
 * @property string|null $label
 * @property int $x
 * @property int $y
 * @property int $width
 * @property int $height
 * @property int $rotation
 * @property string|null $color
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Zone $zone
 */
class FloorElement extends Model
{
    use SoftDeletes;

    public const TYPES = ['bar', 'wall', 'door', 'window', 'column', 'plant', 'kitchen', 'toilet', 'stairs', 'label'];

    /** Default size for each type, in floor plan units. */
    public const SIZES = [
        'bar' => [300, 60],
        'wall' => [300, 12],
        'door' => [80, 80],
        'window' => [120, 12],
        'column' => [40, 40],
        'plant' => [50, 50],
        'kitchen' => [200, 120],
        'toilet' => [100, 100],
        'stairs' => [100, 140],
        'label' => [160, 40],
    ];

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class)->withTrashed();
    }
}
