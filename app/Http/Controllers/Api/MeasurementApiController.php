<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImmutableRecordException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MeasurementPresenter;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Services\MeasurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasurementApiController extends Controller
{
    public function __construct(private MeasurementService $service) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        return response()->json(
            $project->measurements()->with(MeasurementPresenter::RELATIONS)
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest('id')->paginate(30)->withQueryString()
                ->through(MeasurementPresenter::present(...))
        );
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $entry = $this->service->create($project, $this->validated($request, $project), $request->user());

        return response()->json(['data' => MeasurementPresenter::present($entry)], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, MeasurementEntry $measurement): JsonResponse
    {
        if ($measurement->status === 'APPROVED') {
            throw new ImmutableRecordException;
        }

        $data = $this->validated($request, $measurement->project);
        unset($data['client_uuid']);

        return response()->json(['data' => MeasurementPresenter::present($this->service->update($measurement, $data))]);
    }

    public function submit(Request $request, MeasurementEntry $measurement): JsonResponse
    {
        $this->service->submit($measurement, $request->user());

        return response()->json(['data' => MeasurementPresenter::present($measurement->refresh())]);
    }

    private function validated(Request $request, Project $project): array
    {
        return [...$request->validate($this->service->rules($project)), 'is_deduction' => $request->boolean('is_deduction')];
    }
}
