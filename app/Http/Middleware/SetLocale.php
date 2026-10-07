<?php

namespace App\Http\Middleware;

use App\Support\AppSettings;
use App\Support\Translation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (! in_array($locale, Translation::LOCALES, true)) {
            $cookie = $request->cookie('locale');
            $locale = in_array($cookie, Translation::LOCALES, true) ? $cookie : null;
        }

        if ($locale === null) {
            $default = AppSettings::get('default_locale', config('app.locale'));
            $locale = in_array($default, Translation::LOCALES, true) ? $default : 'ca';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
