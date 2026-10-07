<?php

namespace App\Services\Sync;

use App\Events\TpvChanged;
use App\Models\ClientOperation;
use App\Models\Device;
use App\Models\User;
use App\Services\Printing\PrintService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies the operations queued by devices. Each operation carries a client UUID: the result
 * is stored in the same transaction as the change, so a retry returns the stored result
 * instead of applying it twice.
 */
class OperationProcessor
{
    /** @var array<string, OperationHandler> */
    private array $handlers = [];

    /**
     * @param  iterable<OperationHandler>  $handlers
     */
    public function __construct(iterable $handlers, private readonly PrintService $prints)
    {
        foreach ($handlers as $handler) {
            foreach ($handler->types() as $type) {
                $this->handlers[$type] = $handler;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    public function process(array $operations, User $user, ?Device $device): array
    {
        $results = [];
        $scopes = [];

        foreach ($operations as $operation) {
            $result = $this->processOne($operation, $user, $device, $scopes);
            $results[] = $result;

            if ($result['status'] === 'error') {
                break;
            }
        }

        TpvChanged::notify($scopes, $device?->uuid);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<string>  $scopes
     * @return array<string, mixed>
     */
    private function processOne(array $operation, User $user, ?Device $device, array &$scopes): array
    {
        $uuid = (string) ($operation['uuid'] ?? '');
        $type = (string) ($operation['type'] ?? '');
        /** @var array<string, mixed> $payload */
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        if ($uuid === '' || $type === '') {
            return ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'invalid_operation'];
        }

        $existing = ClientOperation::query()->find($uuid);

        if ($existing) {
            return $this->stored($existing);
        }

        $handler = $this->handlers[$type] ?? null;

        if ($handler === null) {
            return $this->rejectAndStore($uuid, $type, $payload, $user, $device, $operation, 'unknown_operation');
        }

        $context = new OperationContext(
            user: $user,
            operator: $this->resolveOperator($operation, $user),
            device: $device,
            clientTime: $this->clientTime($operation),
            uuid: $uuid,
        );

        try {
            $result = DB::transaction(function () use ($handler, $type, $payload, $context, $uuid, $user, $device, $operation) {
                $result = $handler->handle($type, $payload, $context);

                if (is_array($payload['printJobs'] ?? null) && $payload['printJobs'] !== []) {
                    $result['printed'] = $this->prints->storeMany($payload['printJobs'], $context);
                    $context->touch('prints');
                }

                $this->store($uuid, $type, $payload, $user, $device, $operation, 'ok', $result);

                return $result;
            });
        } catch (OperationRejected $e) {
            return $this->rejectAndStore($uuid, $type, $payload, $user, $device, $operation, $e->reason, $e->params);
        } catch (QueryException $e) {
            $existing = ClientOperation::query()->find($uuid);

            if ($existing) {
                return $this->stored($existing);
            }

            Log::error('TPV operation failed', ['uuid' => $uuid, 'type' => $type, 'error' => $e->getMessage()]);

            return ['uuid' => $uuid, 'status' => 'error', 'reason' => 'server_error'];
        } catch (Throwable $e) {
            report($e);

            return ['uuid' => $uuid, 'status' => 'error', 'reason' => 'server_error'];
        }

        array_push($scopes, ...$context->scopes);

        return ['uuid' => $uuid, 'status' => 'ok', 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(ClientOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'status' => $operation->status,
            'duplicate' => true,
            'result' => $operation->status === 'ok' ? $operation->result : null,
            'reason' => $operation->status === 'rejected' ? ($operation->result['reason'] ?? null) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rejectAndStore(string $uuid, string $type, array $payload, User $user, ?Device $device, array $operation, string $reason, array $params = []): array
    {
        try {
            $this->store($uuid, $type, $payload, $user, $device, $operation, 'rejected', ['reason' => $reason, 'params' => $params]);
        } catch (QueryException) {
            // A concurrent retry stored it first.
        }

        return ['uuid' => $uuid, 'status' => 'rejected', 'reason' => $reason, 'params' => $params];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $result
     */
    private function store(string $uuid, string $type, array $payload, User $user, ?Device $device, array $operation, string $status, array $result): void
    {
        ClientOperation::query()->create([
            'uuid' => $uuid,
            'device_id' => $device?->id,
            'user_id' => $this->resolveOperator($operation, $user)->id,
            'type' => $type,
            'payload' => $payload,
            'status' => $status,
            'result' => $result,
            'client_created_at' => $this->clientTime($operation),
            'processed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function resolveOperator(array $operation, User $user): User
    {
        $operatorId = $operation['operatorId'] ?? null;

        if (! is_numeric($operatorId) || (int) $operatorId === $user->id) {
            return $user;
        }

        return User::query()->where('active', true)->find((int) $operatorId) ?? $user;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function clientTime(array $operation): CarbonImmutable
    {
        $raw = $operation['createdAt'] ?? null;

        try {
            $time = is_string($raw) ? CarbonImmutable::parse($raw) : CarbonImmutable::now();
        } catch (Throwable) {
            $time = CarbonImmutable::now();
        }

        return $time->isAfter(now()->addMinutes(5)) ? CarbonImmutable::now() : $time;
    }
}
