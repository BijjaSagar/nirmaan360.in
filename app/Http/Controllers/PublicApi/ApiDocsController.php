<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Services\PublicApi\ApiKeyService;
use Illuminate\View\View;

class ApiDocsController extends Controller
{
    public function __invoke(): View
    {
        return view('modules.public_api.docs', [
            'baseUrl' => url('/api/v1/public'),
            'scopes' => ApiKeyService::SCOPES,
            'defaultRate' => ApiKeyService::DEFAULT_RATE,
        ]);
    }
}
