<?php

namespace App\Http\Controllers\Tpv;

use App\Enums\DeviceType;
use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\PrintJob;
use App\Services\Devices\DeviceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrintJobController extends Controller
{
    public function __construct(private readonly DeviceManager $devices) {}

    public function claim(Request $request, PrintJob $printJob): JsonResponse
    {
        $device = $this->cashier($request);

        $claimed = PrintJob::query()
            ->whereKey($printJob->id)
            ->where(function ($query) use ($device) {
                $query->where('status', 'pending')
                    ->orWhere(function ($query) use ($device) {
                        $query->where('status', 'printing')
                            ->where(function ($query) use ($device) {
                                $query->where('claimed_by_device_id', $device->id)
                                    ->orWhere('updated_at', '<', now()->subMinutes(PrintJob::STALE_CLAIM_MINUTES));
                            });
                    });
            })
            ->update([
                'status' => 'printing',
                'claimed_by_device_id' => $device->id,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return response()->json(['ok' => false, 'status' => $printJob->fresh()?->status], 409);
        }

        TpvChanged::notify(['prints']);

        return response()->json(['ok' => true, 'status' => 'printing']);
    }

    public function ack(Request $request, PrintJob $printJob): JsonResponse
    {
        $this->cashier($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(['printed', 'failed'])],
        ]);

        if (! in_array($printJob->status, ['pending', 'printing'], true)) {
            return response()->json(['ok' => true, 'status' => $printJob->status]);
        }

        $printJob->forceFill([
            'status' => $data['status'],
            'claimed_by_device_id' => null,
        ])->save();

        TpvChanged::notify(['prints']);

        return response()->json(['ok' => true, 'status' => $printJob->status]);
    }

    private function cashier(Request $request): Device
    {
        $device = $this->devices->fromRequest($request);

        if ($device === null || $device->type !== DeviceType::Cashier) {
            abort(403);
        }

        return $device;
    }
}
