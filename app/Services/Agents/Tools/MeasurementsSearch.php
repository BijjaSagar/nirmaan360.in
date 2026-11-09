<?php

namespace App\Services\Agents\Tools;

use App\Models\MeasurementEntry;

class MeasurementsSearch extends Tool
{
    public function name(): string
    {
        return 'measurements_search';
    }

    public function description(): string
    {
        return 'Measurement book entries for a project, newest first. Can limit to entries not yet billed or flagged as deviations.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'boq_item_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'REJECTED']],
            'from' => $this->date('First measurement date to include.'),
            'to' => $this->date('Last measurement date to include.'),
            'unbilled_only' => ['type' => 'boolean', 'description' => 'Only entries not yet on a client bill.'],
            'deviations_only' => ['type' => 'boolean'],
            'limit' => $this->limit(),
        ], ['project_id']);
    }

    public function permission(): string
    {
        return 'measurement.view';
    }

    public function feature(): ?string
    {
        return 'measurement';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);
        $limit = (int) ($input['limit'] ?? 20);

        $entries = MeasurementEntry::with('boqItem')
            ->where('project_id', $project->id)
            ->when($input['boq_item_id'] ?? null, fn ($q, $id) => $q->where('boq_item_id', $id))
            ->when($input['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($input['from'] ?? null, fn ($q, $date) => $q->whereDate('measured_on', '>=', $date))
            ->when($input['to'] ?? null, fn ($q, $date) => $q->whereDate('measured_on', '<=', $date))
            ->when($input['unbilled_only'] ?? false, fn ($q) => $q->whereNull('billed_client_bill_id'))
            ->when($input['deviations_only'] ?? false, fn ($q) => $q->where('deviation', true))
            ->latest('measured_on')
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        $shown = $entries->take($limit);

        return new ToolResult(
            [
                'entries' => $shown->map(fn (MeasurementEntry $entry) => [
                    'id' => $entry->id,
                    'measured_on' => $entry->measured_on->toDateString(),
                    'boq_code' => $entry->boqItem->code,
                    'boq_description' => $entry->boqItem->description,
                    'unit' => $entry->boqItem->unit,
                    'location' => $entry->location,
                    'nos' => $entry->nos,
                    'length' => $entry->length,
                    'breadth' => $entry->breadth,
                    'depth' => $entry->depth,
                    'qty' => $entry->qty,
                    'status' => $entry->status,
                    'deviation' => (bool) $entry->deviation,
                    'billed_to_client' => $entry->billed_client_bill_id !== null,
                ])->all(),
                'more_exist' => $entries->count() > $limit,
            ],
            $shown->map(fn (MeasurementEntry $entry) => ToolResult::source('measurement', $entry->id, $entry->boqItem->code.' · '.$entry->measured_on->format('d M Y'), route('measurements.show', $entry, false)))->all(),
        );
    }
}
