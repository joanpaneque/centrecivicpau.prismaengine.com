<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\HandleInitialSetupLogin;
use App\Actions\Users\CreateInitialAdmin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class InitialSetupController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $this->allowed($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/InitialSetup');
    }

    public function store(Request $request, CreateInitialAdmin $createAdmin): RedirectResponse
    {
        if (! $this->allowed($request)) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::notIn([HandleInitialSetupLogin::DEFAULT_EMAIL]),
            ],
            'password' => ['required', 'string', 'confirmed', Password::defaults(), Rule::notIn([HandleInitialSetupLogin::DEFAULT_PASSWORD])],
            'locale' => ['nullable', Rule::in(['ca', 'es'])],
        ], [
            'email.not_in' => __('tpv.setup_new_email'),
            'password.not_in' => __('tpv.setup_new_password'),
        ]);

        try {
            $user = $createAdmin->handle([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? app()->getLocale(),
            ]);
        } catch (RuntimeException) {
            $request->session()->forget(HandleInitialSetupLogin::SESSION_KEY);

            return redirect()->route('login');
        }

        $request->session()->forget(HandleInitialSetupLogin::SESSION_KEY);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('admin.index');
    }

    private function allowed(Request $request): bool
    {
        return $request->session()->get(HandleInitialSetupLogin::SESSION_KEY) === true
            && User::query()->doesntExist();
    }
}
