<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\WorkerPresenter;
use App\Models\Worker;
use App\Services\WorkerService;
use App\Support\Money;
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
        $data = $request->validate([
            ...$this->service->rules(),
            'type' => ['sometimes', Rule::in(['own', 'contractor'])],
            'daily_wage' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
        ]);

        // Wages are money: a device signed in by someone who may not see them cannot set them.
        if (! Money::visible($request->user())) {
            unset($data['daily_wage']);
        }

        $worker = $this->service->create($data);

        return response()->json(['data' => WorkerPresenter::present($worker)], $worker->wasRecentlyCreated ? 201 : 200);
    }
}
