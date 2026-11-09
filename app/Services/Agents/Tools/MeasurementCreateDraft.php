<?php

namespace App\Services\Agents\Tools;

use App\Services\MeasurementService;
use Illuminate\Support\Facades\Validator;

class MeasurementCreateDraft extends Tool
{
    private const NUMBER = ['type' => ['string', 'null'], 'pattern' => '^\d{1,8}(\.\d{1,3})?$'];

    public function __construct(private MeasurementService $service) {}

    public function name(): string
    {
        return 'measurement_create_draft';
    }

    public function description(): string
    {
        return 'Saves a measurement book entry as a DRAFT: Nos × Length × Breadth × Depth. Numbers are text such as "10.500". Leave out a dimension that does not apply.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'boq_item_id' => ['type' => 'integer'],
            'measured_on' => $this->date('The day measured. Cannot be in the future.'),
            'nos' => ['type' => 'string', 'pattern' => '^\d{1,8}(\.\d{1,3})?$'],
            'length' => self::NUMBER,
            'breadth' => self::NUMBER,
            'depth' => self::NUMBER,
            'location' => ['type' => ['string', 'null'], 'maxLength' => 120],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 255],
        ], ['project_id', 'boq_item_id', 'measured_on', 'nos']);
    }

    public function permission(): string
    {
        return 'measurement.create';
    }

    public function effect(): string
    {
        return self::DRAFT;
    }

    public function feature(): ?string
    {
        return 'measurement';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);

        $payload = [
            'boq_item_id' => $input['boq_item_id'],
            'measured_on' => $input['measured_on'],
            'nos' => $input['nos'],
            'length' => $input['length'] ?? null,
            'breadth' => $input['breadth'] ?? null,
            'depth' => $input['depth'] ?? null,
            'location' => $input['location'] ?? null,
            'description' => $input['description'] ?? null,
            'client_uuid' => $context->clientUuid(),
        ];

        $entry = $this->service->create($project, Validator::make($payload, $this->service->rules($project))->validate(), $context->user);

        return new ToolResult(
            ['entry_id' => $entry->id, 'status' => $entry->status, 'qty' => $entry->qty, 'unit' => $entry->boqItem->unit],
            [ToolResult::source('measurement', $entry->id, $entry->boqItem->code.' · '.$entry->measured_on->format('d M Y'), route('measurements.show', $entry, false), draft: true)],
        );
    }
}
