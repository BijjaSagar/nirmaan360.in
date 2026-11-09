<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImmutableRecordException;
use App\Http\Controllers\Controller;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Services\MeasurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeasurementApiController extends Controller
{
    public function __construct(private MeasurementService $service) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        $entry = $this->service->create($project, $this->validated($request, $project), $request->user());

        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, MeasurementEntry $measurement): JsonResponse
    {
        if ($measurement->status === 'APPROVED') {
            throw new ImmutableRecordException;
        }

        $data = $this->validated($request, $measurement->project);
        unset($data['client_uuid']);

        return response()->json(['data' => $this->service->update($measurement, $data)]);
    }

    public function submit(Request $request, MeasurementEntry $measurement): JsonResponse
    {
        $this->service->submit($measurement, $request->user());

        return response()->json(['data' => $measurement->refresh()]);
    }

    private function validated(Request $request, Project $project): array
    {
        $data = $request->validate([
            'boq_item_id' => ['required', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'work_order_id' => ['nullable', Rule::exists('work_orders', 'id')->where('project_id', $project->id)],
            'measured_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:120'],
            'nos' => ['required', 'numeric', 'gt:0'],
            'length' => ['nullable', 'numeric', 'gt:0'],
            'breadth' => ['nullable', 'numeric', 'gt:0'],
            'depth' => ['nullable', 'numeric', 'gt:0'],
            'is_deduction' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        return [...$data, 'is_deduction' => $request->boolean('is_deduction')];
    }
}
