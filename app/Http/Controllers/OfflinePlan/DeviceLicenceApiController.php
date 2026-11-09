<?php

namespace App\Http\Controllers\OfflinePlan;

use App\Http\Controllers\Controller;
use App\Services\OfflinePlan\DeviceLicenceService;
use App\Services\OfflinePlan\LicenceSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The field app's side of the offline plan: the signing key, the company's mode and its device licence. */
class DeviceLicenceApiController extends Controller
{
    public function __construct(private DeviceLicenceService $licences, private LicenceSigner $signer) {}

    /** Public, so the app can fetch the key once and verify every later token with no internet. */
    public function publicKey(): JsonResponse
    {
        return response()->json($this->signer->describe());
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->licences->status($request->user()));
    }

    public function activate(Request $request): JsonResponse
    {
        $result = $this->licences->activate($request->user(), $request->validate($this->licences->rules()));

        return response()->json($this->licences->issued($result['licence'], $result['token']), $result['created'] ? 201 : 200);
    }

    public function refresh(Request $request): JsonResponse
    {
        $rules = $this->licences->rules();
        $rules['device_name'] = ['nullable', 'string', 'max:120'];

        $result = $this->licences->refresh($request->user(), $request->validate($rules));

        return response()->json($this->licences->issued($result['licence'], $result['token']));
    }

    public function release(Request $request): JsonResponse
    {
        $data = $request->validate(['device_id' => $this->licences->rules()['device_id']]);
        $licence = $this->licences->release($request->user(), $data['device_id']);

        return response()->json(['status' => 'released', 'licence' => $this->licences->present($licence)]);
    }
}
