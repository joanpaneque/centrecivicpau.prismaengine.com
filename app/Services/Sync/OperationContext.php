<?php

namespace App\Services\Sync;

use App\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Who sent an operation and from where. The operator is the person using a shared
 * tablet (selected with PIN), which can differ from the session user.
 */
final class OperationContext
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly User $user,
        public readonly User $operator,
        public readonly ?Device $device,
        public readonly CarbonImmutable $clientTime,
        public readonly string $uuid,
        public array $scopes = [],
    ) {}

    public function touch(string ...$scopes): void
    {
        foreach ($scopes as $scope) {
            if (! in_array($scope, $this->scopes, true)) {
                $this->scopes[] = $scope;
            }
        }
    }
}
