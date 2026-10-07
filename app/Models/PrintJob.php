<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $printer_id
 * @property string|null $printer_name
 * @property string $kind order|receipt|z_report|march|invoice
 * @property string $title
 * @property array<string, mixed> $document
 * @property string $status
 * @property int|null $created_by
 * @property int|null $device_id
 * @property int|null $claimed_by_device_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Printer|null $printer
 * @property-read Device|null $claimedBy
 */
class PrintJob extends Model
{
    public const STALE_CLAIM_MINUTES = 2;

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'document' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'claimed_by_device_id');
    }
}
