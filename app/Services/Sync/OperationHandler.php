<?php

namespace App\Services\Sync;

interface OperationHandler
{
    /**
     * Operation types handled, e.g. ['order.send', 'order.move'].
     *
     * @return list<string>
     */
    public function types(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws OperationRejected
     */
    public function handle(string $type, array $payload, OperationContext $context): array;
}
