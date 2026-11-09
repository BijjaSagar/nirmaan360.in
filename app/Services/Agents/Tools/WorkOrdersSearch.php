<?php

namespace App\Services\Agents\Tools;

use App\Models\WorkOrder;

class WorkOrdersSearch extends Tool
{
    public function name(): string
    {
        return 'work_orders_search';
    }

    public function description(): string
    {
        return 'Work orders for a project, with subcontractor, status and value. Use the id when drafting a subcontractor bill.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'limit' => $this->limit(),
        ], ['project_id']);
    }

    public function permission(): string
    {
        return 'work_order.view';
    }

    public function feature(): ?string
    {
        return 'billing';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);
        $limit = (int) ($input['limit'] ?? 20);

        $orders = WorkOrder::with('party', 'items')->where('project_id', $project->id)->latest('id')->limit($limit + 1)->get();
        $shown = $orders->take($limit);

        return new ToolResult(
            [
                'work_orders' => $shown->map(fn (WorkOrder $order) => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'title' => $order->title,
                    'party' => $order->party?->name,
                    'status' => $order->status,
                    'total_value' => $order->totalValue(),
                ])->all(),
                'more_exist' => $orders->count() > $limit,
            ],
            $shown->map(fn (WorkOrder $order) => ToolResult::source('work_order', $order->id, $order->number, route('work-orders.show', $order, false)))->all(),
        );
    }
}
