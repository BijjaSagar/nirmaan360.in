<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Services\MeasurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Offline outbox for the field app. Measurements are the first supported record type. */
class SyncController extends Controller
{
    public function __construct(private MeasurementService $measurements) {}

    public function push(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('measurement.create'), 403);

        $batch = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.type' => ['required', Rule::in(['measurement'])],
            'items.*.client_uuid' => ['required', 'uuid'],
            'items.*.project_id' => ['required', 'integer'],
            'items.*.data' => ['required', 'array'],
        ])['items'];

        $results = [];

        foreach ($batch as $item) {
            $results[] = ['client_uuid' => $item['client_uuid'], ...$this->pushMeasurement($request, $item)];
        }

        return response()->json(['results' => $results]);
    }

    public function pull(Request $request): JsonResponse
    {
        $since = $request->validate(['since' => ['nullable', 'date']])['since'] ?? null;
        $sinceTime = $since ? Carbon::parse($since) : null;
        $serverTime = now()->toIso8601String();

        return response()->json([
            'server_time' => $serverTime,
            'projects' => Project::when($sinceTime, fn ($q) => $q->where('updated_at', '>', $sinceTime))->get(['id', 'code', 'name', 'status', 'updated_at']),
            'boq_items' => BoqItem::when($sinceTime, fn ($q) => $q->where('updated_at', '>', $sinceTime))->get(['id', 'project_id', 'code', 'description', 'unit', 'qty', 'rate', 'updated_at']),
            'measurements' => MeasurementEntry::when($sinceTime, fn ($q) => $q->where('updated_at', '>', $sinceTime))->get(['id', 'project_id', 'boq_item_id', 'qty', 'status', 'client_uuid', 'updated_at']),
        ]);
    }

    /** One bad record is reported back and does not stop the rest of the batch. */
    private function pushMeasurement(Request $request, array $item): array
    {
        $project = Project::find($item['project_id']);

        if (! $project) {
            return ['status' => 'error', 'errors' => ['project_id' => ['Project not found.']]];
        }

        $validator = Validator::make($item['data'], [
            'boq_item_id' => ['required', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'measured_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:120'],
            'nos' => ['required', 'numeric', 'gt:0'],
            'length' => ['nullable', 'numeric', 'gt:0'],
            'breadth' => ['nullable', 'numeric', 'gt:0'],
            'depth' => ['nullable', 'numeric', 'gt:0'],
            'is_deduction' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ['status' => 'error', 'errors' => $validator->errors()->toArray()];
        }

        $entry = $this->measurements->create($project, [
            ...$validator->validated(),
            'is_deduction' => (bool) ($item['data']['is_deduction'] ?? false),
            'client_uuid' => $item['client_uuid'],
        ], $request->user());

        return ['status' => $entry->wasRecentlyCreated ? 'created' : 'duplicate', 'id' => $entry->id];
    }
}
