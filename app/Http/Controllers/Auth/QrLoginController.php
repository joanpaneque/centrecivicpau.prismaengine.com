<?php

namespace App\Http\Controllers\Auth;

use App\Enums\DeviceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QrLoginController extends Controller
{
    public function __invoke(Request $request, string $token, DeviceManager $devices): RedirectResponse
    {
        $user = User::query()
            ->where('login_token_hash', hash('sha256', $token))
            ->where('active', true)
            ->first();

        if ($user === null) {
            return redirect()->route('login')->with('status', __('tpv.qr_login_invalid'));
        }

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        if ($devices->fromRequest($request) === null) {
            $type = $user->role === UserRole::Kitchen ? DeviceType::Kds : DeviceType::Tablet;
            $devices->register(__('tpv.device_of', ['name' => $user->name]), $type, $user);
        }

        return redirect()->route('tpv');
    }
}
