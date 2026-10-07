<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceType;
use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Services\Devices\DeviceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Devices', [
            'devices' => Device::query()->with('ticketSeries')->orderByDesc('active')->orderBy('name')->get()->map(fn (Device $d) => [
                'id' => $d->id,
                'uuid' => $d->uuid,
                'name' => $d->name,
                'type' => $d->type->value,
                'active' => $d->active,
                'series' => $d->ticketSeries?->code,
                'lastNumber' => $d->ticketSeries?->last_number,
                'lastSeenAt' => $d->last_seen_at?->toIso8601String(),
                'createdAt' => $d->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function update(Request $request, Device $device, DeviceManager $devices): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::enum(DeviceType::class)],
        ]);

        $device->name = $data['name'];
        $devices->changeType($device, DeviceType::from($data['type']));
        TpvChanged::notify(['device']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    /**
     * Revoking keeps the row (tickets and clock-ins reference it) but the cookie stops working.
     */
    public function destroy(Request $request, Device $device): RedirectResponse
    {
        $device->forceFill(['active' => false, 'token_hash' => hash('sha256', bin2hex(random_bytes(24)))])->save();
        AuditLog::record('device.revoke', $device, userId: $request->user()?->id);
        $this->toast(__('tpv.saved'));

        return back();
    }
}
