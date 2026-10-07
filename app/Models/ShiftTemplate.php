<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property string $color
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShiftTemplate extends Model
{
    protected $guarded = ['id'];
}
