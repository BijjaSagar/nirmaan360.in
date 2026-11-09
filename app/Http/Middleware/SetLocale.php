<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = ($request->hasSession() ? $request->session()->get('locale') : null) ?? $request->user()?->locale;

        if ($locale && array_key_exists($locale, config('buildos.locales'))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
