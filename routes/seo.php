<?php

use App\Http\Controllers\PublicController;
use App\Http\Controllers\Seo\SearchFilesController;
use App\Http\Middleware\LocalizedPublicPage;
use Illuminate\Support\Facades\Route;

// Public routes for search engines, loaded from the public part of routes/web.php. They cannot live in
// routes/modules, which is inside the signed-in group. The admin screen is in routes/modules/seo.php.

// Fetched by crawlers and link previews, which keep no cookies, so no session is started for these.
Route::withoutMiddleware('web')->controller(SearchFilesController::class)->group(function () {
    Route::get('/sitemap.xml', 'sitemap')->name('seo.sitemap');
    Route::get('/robots.txt', 'robots')->name('seo.robots');
    Route::get('/brand/share-image', 'shareImage')->name('seo.share-image');
});

// The Hindi and Marathi address of each website page that has one: /hi, /mr/pricing and so on.
// English stays at the plain address. Keep this list in step with the "languages" pages in
// App\Services\Seo\PublicPages; tests/Feature/Seo/LanguageVersionsTest fails if they drift apart.
Route::prefix('{locale}')
    ->where(['locale' => implode('|', array_slice(array_keys(config('buildos.locales')), 1))])
    ->middleware(LocalizedPublicPage::class)
    ->name('localized.')
    ->controller(PublicController::class)
    ->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/pricing', 'pricing')->name('pricing');
        Route::get('/calculators', 'calculators')->name('calculators');
        Route::get('/ai', 'ai')->name('ai');
        Route::get('/contact', 'contact')->name('contact');
    });
