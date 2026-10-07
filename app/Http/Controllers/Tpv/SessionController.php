<?php

namespace App\Http\Controllers\Tpv;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use App\Services\Sync\SnapshotBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    /**
     * Quick waiter switch on a shared tablet: select user + 4-digit PIN.
     */
    public function switchUser(Request $request, DeviceManager $devices, SnapshotBuilder $snapshots): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['required', 'integer'],
            'pin' => ['required', 'digits:4'],
        ]);

        $device = $devices->fromRequest($request);
        abort_if($device === null, 403, __('tpv.device_required'));

        $key = 'pin:'.$device->id.':'.$data['userId'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => __('tpv.pin_locked', ['seconds' => RateLimiter::availableIn($key)])], 429);
        }

        $user = User::query()->where('active', true)->whereKey((int) $data['userId'])->first();

        if ($user === null || ! $user->checkPin($data['pin'])) {
            RateLimiter::hit($key, 120);

            return response()->json(['message' => __('tpv.pin_wrong')], 422);
        }

        RateLimiter::clear($key);

        $previous = $request->user();
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        AuditLog::record('session.switch', $user, ['from' => $previous?->id], userId: $user->id, deviceId: $device->id);

        return response()->json(['me' => $snapshots->me($user)]);
    }

    public function locale(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['ca', 'es'])]]);

        $user = $request->user();

        if ($user) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }

        Cookie::queue('locale', $data['locale'], 60 * 24 * 365 * 5);

        return response()->json(['locale' => $data['locale']]);
    }
}
