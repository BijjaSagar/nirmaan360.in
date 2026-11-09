<?php

namespace App\Services\Agents\Tools;

use App\Models\RaBill;
use App\Services\ApprovalService;

class RaBillSubmitForApproval extends Tool
{
    public function __construct(private ApprovalService $approvals) {}

    public function name(): string
    {
        return 'ra_bill_submit_for_approval';
    }

    public function description(): string
    {
        return 'Sends a draft RA bill into its approval chain. A person must allow this first, and the run waits for them. Approving the bill itself is always done by people in that chain.';
    }

    public function schema(): array
    {
        return $this->objectSchema(['bill_id' => ['type' => 'integer']], ['bill_id']);
    }

    public function permission(): string
    {
        return 'ra_bill.generate';
    }

    public function effect(): string
    {
        return self::APPROVAL;
    }

    public function feature(): ?string
    {
        return 'billing';
    }

    public function actionSummary(array $input, ToolContext $context): string
    {
        $bill = $this->bill($input, $context);

        return 'Submit RA bill '.$bill->number.' ('.inr($bill->net_payable).') for approval';
    }

    public function actionProjectId(array $input, ToolContext $context): ?int
    {
        return $this->bill($input, $context)->project_id;
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $bill = $this->bill($input, $context);

        $this->approvals->submit($bill, $context->user);
        $bill->refresh();

        return new ToolResult(
            ['bill_id' => $bill->id, 'number' => $bill->number, 'status' => $bill->status],
            [ToolResult::source('ra_bill', $bill->id, $bill->number, route('ra-bills.show', $bill, false))],
        );
    }

    private function bill(array $input, ToolContext $context): RaBill
    {
        $bill = RaBill::where('organization_id', $context->organizationId())->find($input['bill_id'])
            ?? throw new ToolFailure('There is no RA bill with that id.');

        $context->project($bill->project_id);

        if (! $bill->isEditable()) {
            throw new ToolFailure('Only a draft or returned bill can be submitted. This one is '.$bill->status.'.');
        }

        return $bill;
    }
}
