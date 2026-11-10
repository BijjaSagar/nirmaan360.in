<?php

namespace App\Http\Middleware;

use App\Services\Seo\PublicPages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a website page in the language named in its address: /hi/pricing is the pricing page in Hindi.
 * A search engine carries no session, so the address is the only way it can ask for a language.
 *
 * While the page is drawn, links to other website pages keep the same prefix, so someone reading in
 * Hindi stays in Hindi and a crawler finds the Hindi pages from each other.
 */
class LocalizedPublicPage
{
    public function __construct(private PublicPages $pages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $locale = (string) $route->parameter('locale');

        // The controllers are shared with the plain addresses and do not take a language.
        $route->forgetParameter('locale');
        App::setLocale($locale);

        // A first-time visitor keeps this language on the sign-in pages too. A choice already made is left alone.
        if ($request->hasSession() && ! $request->session()->has('locale') && $request->user() === null) {
            $request->session()->put('locale', $locale);
        }

        $before = URL::pathFormatter();

        URL::formatPathUsing(function (string $path, mixed $target = null) use ($locale) {
            $page = $target instanceof Route ? $this->pages->forRoute($target) : null;
            $plain = $page !== null && ! str_starts_with((string) $target->getName(), PublicPages::LOCALIZED);

            return $plain && $this->pages->hasLanguages($page) ? '/'.$locale.rtrim($path, '/') : $path;
        });

        try {
            return $next($request);
        } finally {
            URL::formatPathUsing($before);
        }
    }
}
