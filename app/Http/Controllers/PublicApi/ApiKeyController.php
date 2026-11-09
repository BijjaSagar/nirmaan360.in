<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\CompanyApiKey;
use App\Services\PublicApi\ApiKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $service) {}

    public function index(): View
    {
        return $this->page();
    }

    /**
     * The new key is shown on this response only. It is not redirected or flashed, so it never lands in
     * the session store, and nothing readable is kept.
     */
    public function store(Request $request): View
    {
        $created = $this->service->create($request->user(), $request->validate($this->service->rules()));

        return $this->page(['name' => $created['key']->name, 'secret' => $created['secret']]);
    }

    public function revoke(CompanyApiKey $key): RedirectResponse
    {
        $this->service->revoke($key);

        return back()->with('success', __('Key revoked. It stops working straight away.'));
    }

    private function page(?array $newKey = null): View
    {
        return view('modules.public_api.keys', [
            'keys' => CompanyApiKey::with('creator')->latest('id')->get(),
            'scopes' => ApiKeyService::SCOPES,
            'defaultRate' => ApiKeyService::DEFAULT_RATE,
            'maxRate' => ApiKeyService::MAX_RATE,
            'newKey' => $newKey,
        ]);
    }
}
