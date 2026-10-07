<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $uuid
 * @property int|null $device_id
 * @property int|null $user_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property array<string, mixed>|null $result
 * @property Carbon|null $client_created_at
 * @property Carbon $processed_at
 */
class ClientOperation extends Model
{
    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'client_created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
