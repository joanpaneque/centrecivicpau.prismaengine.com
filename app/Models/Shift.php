<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property int|null $shift_template_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read ShiftTemplate|null $template
 */
class Shift extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function startsAt(): Carbon
    {
        return $this->date->copy()->setTimeFromTimeString($this->start_time);
    }

    /**
     * Shifts ending before they start finish on the following day.
     */
    public function endsAt(): Carbon
    {
        $end = $this->date->copy()->setTimeFromTimeString($this->end_time);

        return $end->lessThanOrEqualTo($this->startsAt()) ? $end->addDay() : $end;
    }

    public function plannedMinutes(): int
    {
        return max(0, (int) $this->startsAt()->diffInMinutes($this->endsAt()) - $this->break_minutes);
    }
}
