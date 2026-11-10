<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Services\Seo\Sitemap;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The files search engines and link previews ask for by name. Public, with no session. */
class SearchFilesController extends Controller
{
    private const CACHE = 'public, max-age=3600';

    public function sitemap(Sitemap $sitemap): Response
    {
        return response($sitemap->xml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => self::CACHE]);
    }

    public function robots(Sitemap $sitemap): Response
    {
        return response($sitemap->robots(route('seo.sitemap')), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::CACHE]);
    }

    /** The share picture the admin uploaded. The built-in one is a plain file in public/images. */
    public function shareImage(): StreamedResponse
    {
        $path = site_setting('share_image_path');
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
