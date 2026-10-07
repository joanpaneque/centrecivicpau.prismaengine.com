<?php

namespace App\Http\Controllers\Tpv;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Services\Devices\DeviceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShellController extends Controller
{
    public function show(Request $request, DeviceManager $devices): Response
    {
        $device = $devices->fromRequest($request);

        return Inertia::render('pos/Shell', [
            'device' => $device ? ['uuid' => $device->uuid, 'name' => $device->name, 'type' => $device->type->value] : null,
        ]);
    }

    public function registerDevice(Request $request, DeviceManager $devices): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::enum(DeviceType::class)],
        ]);

        $type = DeviceType::from($data['type']);

        if ($type !== DeviceType::Tablet && ! $user->isAdmin() && ! ($type === DeviceType::Kds && $user->isKitchen())) {
            abort(403, __('tpv.device_type_admin_only'));
        }

        $current = $devices->fromRequest($request);

        $device = $current
            ? tap($devices->changeType($current, $type), fn ($d) => $d->forceFill(['name' => $data['name']])->save())
            : $devices->register($data['name'], $type, $user);

        return response()->json(['device' => ['uuid' => $device->uuid, 'name' => $device->name, 'type' => $device->type->value]]);
    }
}
