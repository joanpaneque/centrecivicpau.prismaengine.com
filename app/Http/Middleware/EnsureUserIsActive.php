<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return $request->expectsJson()
                ? response()->json(['message' => __('tpv.inactive_user')], 401)
                : redirect()->route('login');
        }

        return $next($request);
    }
}
