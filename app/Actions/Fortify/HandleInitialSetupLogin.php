<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the users table is empty, the default credentials open the first-run wizard.
 * Once any user exists this step never matches again.
 */
class HandleInitialSetupLogin
{
    public const DEFAULT_EMAIL = 'joanpd0@gmail.com';

    public const DEFAULT_PASSWORD = '1234';

    public const SESSION_KEY = 'initial_setup';

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (
            mb_strtolower((string) $request->input('email')) === self::DEFAULT_EMAIL
            && hash_equals(self::DEFAULT_PASSWORD, (string) $request->input('password'))
            && User::query()->doesntExist()
        ) {
            $request->session()->regenerate();
            $request->session()->put(self::SESSION_KEY, true);

            return $this->redirect($request);
        }

        return $next($request);
    }

    private function redirect(Request $request): Response
    {
        return $request->wantsJson()
            ? response()->json(['redirect' => route('initial-setup')])
            : redirect()->route('initial-setup');
    }
}
