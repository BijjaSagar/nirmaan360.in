<?php

namespace App\Services\Agents\Tools;

use App\Models\RaBill;

class RaBillsSearch extends Tool
{
    public function name(): string
    {
        return 'ra_bills_search';
    }

    public function description(): string
    {
        return 'RA bills for a project, newest first, with gross, deductions, GST and net payable.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'type' => ['type' => 'string', 'enum' => ['client', 'subcontractor']],
            'status' => ['type' => 'string', 'enum' => ['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'REJECTED', 'PAID', 'CANCELLED']],
            'limit' => $this->limit(),
        ], ['project_id']);
    }

    public function permission(): string
    {
        return 'ra_bill.view';
    }

    public function feature(): ?string
    {
        return 'billing';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);
        $limit = (int) ($input['limit'] ?? 20);

        $bills = RaBill::with('party', 'deductions')
            ->where('project_id', $project->id)
            ->when($input['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($input['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        $shown = $bills->take($limit);

        return new ToolResult(
            [
                'bills' => $shown->map(fn (RaBill $bill) => [
                    'id' => $bill->id,
                    'number' => $bill->number,
                    'type' => $bill->type,
                    'party' => $bill->party?->name,
                    'period_from' => $bill->period_from->toDateString(),
                    'period_to' => $bill->period_to->toDateString(),
                    'status' => $bill->status,
                    'gross_amount' => $bill->gross_amount,
                    'deductions' => $bill->deductions->map(fn ($row) => ['label' => $row->label, 'amount' => $row->amount])->all(),
                    'gst_amount' => $bill->gst_amount,
                    'net_payable' => $bill->net_payable,
                ])->all(),
                'more_exist' => $bills->count() > $limit,
            ],
            $shown->map(fn (RaBill $bill) => ToolResult::source('ra_bill', $bill->id, $bill->number, route('ra-bills.show', $bill, false)))->all(),
        );
    }
}
