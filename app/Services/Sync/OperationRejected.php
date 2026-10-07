<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * A business rule refused the operation. It is stored as rejected so retries do not loop,
 * and the device is told why so it can show it and resync.
 */
class OperationRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
