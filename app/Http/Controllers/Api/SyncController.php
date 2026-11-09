<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use App\Support\ClientTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Offline outbox and delta pull for the field app. Permissions are checked per item. */
class SyncController extends Controller
{
    public function __construct(private SyncService $sync) {}

    public function push(Request $request): JsonResponse
    {
        // Only the batch shape is checked here: a bad item is reported in its own result.
        $items = $request->validate(['items' => ['required', 'array', 'max:200']])['items'];

        return response()->json(['results' => $this->sync->push($items, $request->user())]);
    }

    public function pull(Request $request): JsonResponse
    {
        // An unencoded "+05:30" in a query string arrives as " 05:30".
        $since = preg_replace('/(T[\d:.]+) (\d{2}:?\d{2})$/', '$1+$2', (string) $request->query('since'));
        Validator::make(['since' => $since], ['since' => ['nullable', 'date']])->validate();

        return response()->json($this->sync->pull($request->user(), ClientTime::parse($since)));
    }

    public function upload(Request $request): JsonResponse
    {
        $mimes = $request->input('type') === 'petty_bill' ? 'jpg,jpeg,png,webp,pdf' : 'jpg,jpeg,png,webp';

        $data = $request->validate([
            'type' => ['required', Rule::in(['dpr_photo', 'petty_bill'])],
            'client_uuid' => ['required', 'uuid'],
            'parent_client_uuid' => ['nullable', 'uuid', 'required_without:parent_id'],
            'parent_id' => ['nullable', 'integer'],
            'file' => ['required', 'file', 'mimes:'.$mimes, 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
            'taken_at' => ['nullable', 'date'],
        ]);

        $result = $this->sync->upload($data, $request->file('file'), $request->user());

        return response()->json($result, $result['status'] === 'created' ? 201 : 200);
    }
}
