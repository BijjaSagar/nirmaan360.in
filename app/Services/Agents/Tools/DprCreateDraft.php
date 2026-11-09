<?php

namespace App\Services\Agents\Tools;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\Site;
use App\Services\DprService;
use Illuminate\Support\Facades\Validator;

class DprCreateDraft extends Tool
{
    public function __construct(private DprService $service) {}

    public function name(): string
    {
        return 'dpr_create_draft';
    }

    public function description(): string
    {
        return 'Saves a daily progress report as a DRAFT for a person to confirm or edit. Each work row must be linked to a BOQ item found with boq_search. Quantities are in the BOQ item\'s unit, written as text such as "22.000". If the project has more than one site, site_id is required.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'site_id' => ['type' => ['integer', 'null']],
            'report_date' => $this->date('The day the report is for. Cannot be in the future.'),
            'weather' => ['type' => ['string', 'null'], 'maxLength' => 60],
            'summary' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            'hindrances' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            'manpower' => [
                'type' => 'array',
                'items' => $this->objectSchema([
                    'trade' => ['type' => 'string', 'maxLength' => 60],
                    'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                ], ['trade', 'count']),
            ],
            'machinery' => [
                'type' => 'array',
                'items' => $this->objectSchema([
                    'name' => ['type' => 'string', 'maxLength' => 80],
                    'hours' => ['type' => 'number', 'minimum' => 0, 'maximum' => 24],
                ], ['name', 'hours']),
            ],
            'work' => [
                'type' => 'array',
                'items' => $this->objectSchema([
                    'boq_item_id' => ['type' => 'integer'],
                    'description' => ['type' => ['string', 'null'], 'maxLength' => 255],
                    'qty' => ['type' => 'string', 'pattern' => '^\d{1,8}(\.\d{1,3})?$'],
                ], ['boq_item_id', 'qty']),
            ],
        ], ['project_id', 'report_date', 'manpower', 'work']);
    }

    public function permission(): string
    {
        return 'dpr.create';
    }

    public function effect(): string
    {
        return self::DRAFT;
    }

    public function feature(): ?string
    {
        return 'site';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);
        $site = $this->resolveSite($project, $input['site_id'] ?? null);

        $payload = [
            'report_date' => $input['report_date'],
            'site_id' => $site?->id,
            'weather' => $input['weather'] ?? null,
            'summary' => $input['summary'] ?? null,
            'hindrances' => $input['hindrances'] ?? null,
            'manpower' => $input['manpower'],
            'machinery' => $input['machinery'] ?? [],
            'work' => $input['work'],
            'client_uuid' => $context->clientUuid(),
        ];

        $dpr = $this->service->create($project, Validator::make($payload, $this->service->rules($project))->validate(), $context->user);
        $dpr->load('workItems.boqItem', 'manpower');

        return new ToolResult(
            [
                'dpr_id' => $dpr->id,
                'status' => $dpr->status,
                'report_date' => $dpr->report_date->toDateString(),
                'site' => $site?->name,
                'manpower' => $dpr->manpower->map(fn ($row) => ['trade' => $row->trade, 'count' => (int) $row->count])->all(),
                'work' => $dpr->workItems->map(fn ($row) => [
                    'boq_code' => $row->boqItem?->code,
                    'description' => $row->boqItem?->description,
                    'qty' => $row->qty,
                    'unit' => $row->boqItem instanceof BoqItem ? $row->boqItem->unit : null,
                ])->all(),
            ],
            [ToolResult::source('dpr', $dpr->id, 'DPR '.$dpr->report_date->format('d M Y').' · '.$project->name, route('dprs.show', $dpr, false), draft: true)],
        );
    }

    /** With more than one site the agent has to say which, so it never guesses. */
    private function resolveSite(Project $project, ?int $siteId): ?Site
    {
        $sites = $project->sites;

        if ($siteId === null) {
            if ($sites->count() > 1) {
                throw new ToolFailure('This project has more than one site ('.$sites->pluck('name')->join(', ').'). Ask the user which site this report is for.');
            }

            return $sites->first();
        }

        return $sites->firstWhere('id', $siteId) ?? throw new ToolFailure('Site id '.$siteId.' is not on this project.');
    }
}
