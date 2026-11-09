<?php

namespace App\Services\Agents\Tools;

use App\Models\BoqItem;
use App\Support\Dec;

class BoqSearch extends Tool
{
    public function name(): string
    {
        return 'boq_search';
    }

    public function description(): string
    {
        return 'Finds BOQ items in a project by code or description, with BOQ, executed, measured, approved and billed quantities. BOQ text is usually English, so search with English words such as "slab" or "plaster".';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'query' => ['type' => 'string', 'maxLength' => 100, 'description' => 'One or two words. Leave out to list items.'],
            'limit' => $this->limit(),
        ], ['project_id']);
    }

    public function permission(): string
    {
        return 'boq.view';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);
        $limit = (int) ($input['limit'] ?? 20);

        $query = BoqItem::withLedger()->where('project_id', $project->id)->orderBy('sort')->orderBy('id');

        if (filled($input['query'] ?? null)) {
            $this->whereContains($query, ['code', 'description'], $input['query']);
        }

        $items = $query->limit($limit + 1)->get();
        $shown = $items->take($limit);
        $url = route('boq.index', $project, false);

        return new ToolResult(
            [
                'items' => $shown->map(fn (BoqItem $item) => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'boq_qty' => $item->qty,
                    'rate' => $item->rate,
                    'amount' => $item->amount(),
                    'executed_qty' => Dec::qty($item->executed_qty),
                    'measured_qty' => Dec::qty($item->measured_qty),
                    'approved_qty' => Dec::qty($item->approved_qty),
                    'billed_qty' => Dec::qty($item->billed_qty),
                    'balance_qty' => $item->balanceQty(),
                ])->all(),
                'more_exist' => $items->count() > $limit,
            ],
            $shown->map(fn (BoqItem $item) => ToolResult::source('boq_item', $item->id, $item->code.' · '.mb_substr($item->description, 0, 60), $url))->all(),
        );
    }
}
