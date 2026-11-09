<?php

namespace App\Services\Agents\Tools;

use App\Models\Dpr;
use App\Services\DprService;

class DprSubmitForApproval extends Tool
{
    public function __construct(private DprService $service) {}

    public function name(): string
    {
        return 'dpr_submit_for_approval';
    }

    public function description(): string
    {
        return 'Sends a draft DPR into its approval chain. A person must allow this first, and the run waits for them.';
    }

    public function schema(): array
    {
        return $this->objectSchema(['dpr_id' => ['type' => 'integer']], ['dpr_id']);
    }

    public function permission(): string
    {
        return 'dpr.create';
    }

    public function effect(): string
    {
        return self::APPROVAL;
    }

    public function feature(): ?string
    {
        return 'site';
    }

    public function actionSummary(array $input, ToolContext $context): string
    {
        $dpr = $this->dpr($input, $context);

        return 'Submit DPR '.$dpr->report_date->format('d M Y').' · '.$dpr->project->name.' for approval';
    }

    public function actionProjectId(array $input, ToolContext $context): ?int
    {
        return $this->dpr($input, $context)->project_id;
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $dpr = $this->dpr($input, $context);

        $this->service->submit($dpr, $context->user);
        $dpr->refresh();

        return new ToolResult(
            ['dpr_id' => $dpr->id, 'status' => $dpr->status],
            [ToolResult::source('dpr', $dpr->id, 'DPR '.$dpr->report_date->format('d M Y'), route('dprs.show', $dpr, false))],
        );
    }

    private function dpr(array $input, ToolContext $context): Dpr
    {
        $dpr = Dpr::where('organization_id', $context->organizationId())->find($input['dpr_id'])
            ?? throw new ToolFailure('There is no DPR with that id.');

        $context->project($dpr->project_id);

        if (! $dpr->isEditable()) {
            throw new ToolFailure('Only a draft or returned DPR can be submitted. This one is '.$dpr->status.'.');
        }

        return $dpr;
    }
}
