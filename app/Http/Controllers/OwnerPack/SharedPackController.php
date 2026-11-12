<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Services\OwnerPack\OwnerUpdateShareService;
use App\Services\OwnerPack\SharedPack;
use App\Services\OwnerPack\SharedPhoto;
use Illuminate\Http\Response;

/**
 * What a client sees through a link, with no account. The route checks the signature; SharedPack
 * checks the token, the date, the withdrawal, the status and the company's plan, and loads nothing
 * but the one document the link names.
 */
class SharedPackController extends Controller
{
    /** Kept away from search engines, shared caches and the address a browser hands to other sites. */
    public const HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function quotation(string $id, string $token, SharedPack $pack): Response
    {
        // A number too long to be an id names nothing.
        $shared = strlen($id) <= 18 ? $pack->quotation((int) $id, $token) : null;

        if ($shared === null) {
            return $this->inactive();
        }

        // The page is written in the language of the quotation, whatever language the visitor's browser last used here.
        app()->setLocale($shared->quotation->language);

        return response()->view('modules.owner_pack.shared.quotation', ['shared' => $shared])->withHeaders(self::HEADERS);
    }

    public function update(string $id, string $token, SharedPack $pack, OwnerUpdateShareService $shares): Response
    {
        $shared = strlen($id) <= 18 ? $pack->update((int) $id, $token) : null;

        if ($shared === null) {
            return $this->inactive();
        }

        // The page is written in the language of the update, whatever language the visitor's browser last used here.
        app()->setLocale($shared->update->language);

        $photoUrls = [];

        foreach ($shared->photos as $photo) {
            $photoUrls[$photo['id']] = $shares->photoUrl($shared->update, $photo['id']);
        }

        return response()->view('modules.owner_pack.shared.update', ['shared' => $shared, 'photoUrls' => $photoUrls])->withHeaders(self::HEADERS);
    }

    /**
     * One photo of a shared update. It is served only while the link of the update itself would open
     * and the update names this photo, and it is sent as a picture drawn again by the server, never
     * as the stored file.
     */
    public function photo(string $id, string $token, string $photo, SharedPack $pack, SharedPhoto $photos): Response
    {
        $shared = strlen($id) <= 18 ? $pack->update((int) $id, $token) : null;

        if ($shared === null) {
            return $this->inactive();
        }

        $path = strlen($photo) <= 18 ? ($shared->paths[(int) $photo] ?? null) : null;
        $jpeg = $path === null ? null : $photos->jpeg($path);

        if ($jpeg === null) {
            return response('', 404)->withHeaders(self::HEADERS);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($jpeg),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            ...self::HEADERS,
        ]);
    }

    /** One plain page for every link that does not open, so it tells a visitor nothing about why. */
    public function inactive(): Response
    {
        return response()->view('modules.owner_pack.shared.inactive', [], 410)->withHeaders(self::HEADERS);
    }
}
