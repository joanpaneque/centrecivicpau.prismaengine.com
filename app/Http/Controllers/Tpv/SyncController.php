<?php

namespace App\Http\Controllers\Tpv;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use App\Services\Sync\OperationProcessor;
use App\Services\Sync\SnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        private readonly DeviceManager $devices,
        private readonly SnapshotBuilder $snapshots,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        return $this->snapshot($request, null);
    }

    public function pull(Request $request): JsonResponse
    {
        $since = null;

        try {
            $since = $request->filled('since') ? CarbonImmutable::parse((string) $request->query('since')) : null;
        } catch (\Throwable) {
        }

        return $this->snapshot($request, $since);
    }

    public function push(Request $request, OperationProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'max:100'],
            'operations.*.uuid' => ['required', 'uuid'],
            'operations.*.type' => ['required', 'string', 'max:60'],
            'operations.*.payload' => ['present', 'array'],
            'operations.*.createdAt' => ['nullable', 'string'],
            'operations.*.operatorId' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $device = $this->devices->fromRequest($request);

        if ($device) {
            $this->devices->touch($device);
        }

        /** @var list<array<string, mixed>> $operations */
        $operations = array_values($data['operations']);

        return response()->json([
            'results' => $processor->process($operations, $user, $device),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    private function snapshot(Request $request, ?CarbonImmutable $since): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $device = $this->devices->fromRequest($request);

        if ($device) {
            $this->devices->touch($device);
        }

        return response()->json($this->snapshots->build($user, $device, $since));
    }
}
