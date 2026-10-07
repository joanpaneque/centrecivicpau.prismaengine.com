<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForcePasswordChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

class ForcePasswordChangeController extends Controller
{
    /**
     * Show the forced password change form.
     */
    public function edit(): Response
    {
        return Inertia::render('auth/ForcePasswordChange', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Update the user's password and clear the forced-change flag.
     */
    public function update(ForcePasswordChangeRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Contraseña actualizada correctamente.',
        ]);

        return redirect()->intended(Fortify::redirects('login'));
    }
}
