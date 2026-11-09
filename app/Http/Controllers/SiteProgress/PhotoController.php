<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Models\ProgressPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Photos are private: they are read through here, after the company and project checks on the model. */
class PhotoController extends Controller
{
    public function show(ProgressPhoto $progressPhoto): StreamedResponse
    {
        abort_unless(Storage::exists($progressPhoto->path), 404);

        return Storage::response($progressPhoto->path);
    }
}
