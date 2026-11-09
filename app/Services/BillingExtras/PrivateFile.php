<?php

namespace App\Services\BillingExtras;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams a stored upload. Files live outside the web root and are reached only through signed-in routes. */
final class PrivateFile
{
    public static function response(string $path): StreamedResponse
    {
        abort_unless(Storage::exists($path), 404);

        return Storage::response($path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
