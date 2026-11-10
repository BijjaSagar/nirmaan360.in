<?php

use App\Http\Controllers\Seo\SeoSettingsController;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

// The sitemap, robots.txt and the language versions of the website pages are public, so they are in routes/seo.php.
Route::prefix('admin')->name('admin.')->middleware(EnsurePlatformAdmin::class)->controller(SeoSettingsController::class)->group(function () {
    Route::get('/seo', 'edit')->name('seo');
    Route::patch('/seo', 'update')->name('seo.update');
});
