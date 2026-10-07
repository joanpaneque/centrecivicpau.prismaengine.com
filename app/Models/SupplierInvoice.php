<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $supplier
 * @property Carbon|null $invoice_date
 * @property int|null $amount
 * @property string|null $notes
 * @property string $file_path
 * @property string $mime_type
 * @property string|null $original_name
 * @property int $size
 * @property string $ocr_status none|pending|done|failed
 * @property array<string, mixed>|null $ocr_data
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $uploader
 */
class SupplierInvoice extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'ocr_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
