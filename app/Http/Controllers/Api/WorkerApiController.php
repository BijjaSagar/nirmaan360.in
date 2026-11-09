<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\WorkerPresenter;
use App\Models\Worker;
use App\Services\WorkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkerApiController extends Controller
{
    public function __construct(private WorkerService $service) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Worker::where('is_active', true)->orderBy('trade')->orderBy('name')->get()->map(WorkerPresenter::present(...)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // The app's quick-add form has no wage field, so these two take the service's defaults.
        $worker = $this->service->create($request->validate([
            ...$this->service->rules(),
            'type' => ['sometimes', Rule::in(['own', 'contractor'])],
            'daily_wage' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
        ]));

        return response()->json(['data' => WorkerPresenter::present($worker)], $worker->wasRecentlyCreated ? 201 : 200);
    }
}
