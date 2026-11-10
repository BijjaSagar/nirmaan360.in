<?php

namespace App\Http\Controllers;

use App\Services\Seo\PublicPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale, PublicPages $pages): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('buildos.locales')), 404);

        $request->session()->put('locale', $locale);
        $request->user()?->update(['locale' => $locale]);

        // A website page has an address per language (/hi/pricing), so going straight back
        // would show the old language again. Send the visitor to the same page in the new one.
        $previous = url()->previous();

        return redirect()->to($pages->translate($previous, $locale) ?? $previous);
    }
}
