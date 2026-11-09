<?php

namespace App\Services\Agents\Tools;

use App\Models\WorkOrder;
use App\Services\RaBillService;

class RaBillCreateDraft extends Tool
{
    public function __construct(private RaBillService $service) {}

    public function name(): string
    {
        return 'ra_bill_create_draft';
    }

    public function description(): string
    {
        return 'Builds an RA bill as a DRAFT from approved, unbilled measurements up to the period end. The app applies rates, retention, advance recovery, TDS and GST itself. A subcontractor bill needs a work_order_id. Nobody is paid and nothing is approved by this.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'type' => ['type' => 'string', 'enum' => ['client', 'subcontractor']],
            'period_from' => $this->date('Start of the billing period.'),
            'period_to' => $this->date('End of the billing period.'),
            'work_order_id' => ['type' => ['integer', 'null']],
            'material_recovery' => ['type' => ['string', 'null'], 'pattern' => '^\d{1,11}(\.\d{1,2})?$', 'description' => 'Rupees to recover for material supplied, as text.'],
            'with_gst' => ['type' => 'boolean', 'description' => 'Default true.'],
        ], ['project_id', 'type', 'period_from', 'period_to']);
    }

    public function permission(): string
    {
        return 'ra_bill.generate';
    }

    public function effect(): string
    {
        return self::DRAFT;
    }

    public function feature(): ?string
    {
        return 'billing';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);

        if ($input['period_to'] < $input['period_from']) {
            throw new ToolFailure('period_to cannot be before period_from.');
        }

        $workOrder = null;

        if ($input['type'] === 'subcontractor' && filled($input['work_order_id'] ?? null)) {
            $workOrder = WorkOrder::where('project_id', $project->id)->find($input['work_order_id'])
                ?? throw new ToolFailure('That work order is not on this project.');
        }

        $bill = $this->service->generate(
            $project,
            $input['type'],
            $input['period_from'],
            $input['period_to'],
            $context->user,
            $workOrder,
            $input['material_recovery'] ?? 0,
            $input['with_gst'] ?? true,
        )->load('items', 'deductions');

        return new ToolResult(
            [
                'bill_id' => $bill->id,
                'number' => $bill->number,
                'status' => $bill->status,
                'gross_amount' => $this->money($context, $bill->gross_amount),
                'deductions' => $bill->deductions->map(fn ($row) => ['label' => $row->label, 'amount' => $this->money($context, $row->amount)])->all(),
                'gst_amount' => $this->money($context, $bill->gst_amount),
                'net_payable' => $this->money($context, $bill->net_payable),
                'lines' => $bill->items->map(fn ($line) => [
                    'code' => $line->code,
                    'unit' => $line->unit,
                    'previous_qty' => $line->prev_qty,
                    'current_qty' => $line->current_qty,
                    'cumulative_qty' => $line->cumulative_qty,
                    'current_amount' => $this->money($context, $line->current_amount),
                ])->all(),
            ],
            [ToolResult::source('ra_bill', $bill->id, $bill->number, route('ra-bills.show', $bill, false), draft: true)],
        );
    }
}
