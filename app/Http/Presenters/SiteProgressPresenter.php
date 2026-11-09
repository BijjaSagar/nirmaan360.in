<?php

namespace App\Http\Presenters;

use App\Models\MepStageCell;
use App\Models\ProgressEntry;
use App\Models\ProgressPhoto;
use App\Models\ProjectFloor;

/**
 * What the mobile app receives for site progress. Every field is named here, so a rate, an amount or a budget
 * cannot reach a phone by accident when a model gains a column.
 */
final class SiteProgressPresenter
{
    public static function entry(ProgressEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'client_uuid' => $entry->client_uuid,
            'project_id' => $entry->project_id,
            'boq_item_id' => $entry->boq_item_id,
            'project_floor_id' => $entry->project_floor_id,
            'work_date' => $entry->work_date?->toDateString(),
            'qty_done' => $entry->qty_done,
            'remark' => $entry->remark,
            'is_pending' => (bool) $entry->is_pending,
            'corrects_entry_id' => $entry->corrects_entry_id,
            'resolves_entry_id' => $entry->resolves_entry_id,
            'entered_by' => $entry->entered_by,
            'entered_by_name' => $entry->relationLoaded('enteredBy') ? $entry->enteredBy?->name : null,
            'photos' => $entry->relationLoaded('photos') ? $entry->photos->map(self::photo(...))->values()->all() : [],
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    public static function photo(ProgressPhoto $photo): array
    {
        return ['id' => $photo->id, 'client_uuid' => $photo->client_uuid, 'caption' => $photo->caption, 'taken_at' => $photo->taken_at?->toIso8601String()];
    }

    /** A floor row from ProgressReport::floors(), with its flats. */
    public static function floor(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'parent_id' => $row['parent_id'],
            'percent' => $row['percent'],
            'targets' => $row['targets'],
            'pending' => $row['pending'],
            'mep_pending' => $row['mep_pending'],
            'children' => array_map(self::floor(...), $row['children'] ?? []),
        ];
    }

    /** A floor line from ProgressReport::floorDetail(). */
    public static function floorDetail(array $detail): array
    {
        return [
            ...self::floor($detail),
            'lines' => array_map(fn (array $line) => self::line($line), $detail['lines']),
            'pending_entries' => $detail['pending_entries']->map(self::pendingEntry(...))->values()->all(),
        ];
    }

    public static function line(array $line): array
    {
        return [
            'boq_item_id' => $line['id'],
            'code' => $line['code'],
            'description' => $line['description'],
            'unit' => $line['unit'],
            'planned_qty' => $line['planned_qty'],
            'done_qty' => $line['done_qty'],
            'balance_qty' => $line['balance_qty'],
            'percent' => $line['percent'],
        ];
    }

    /** A BOQ line from ProgressReport::items(): quantities and a percentage, never a rate. */
    public static function item(array $item): array
    {
        return [
            'id' => $item['id'],
            'section' => $item['section'],
            'code' => $item['code'],
            'description' => $item['description'],
            'unit' => $item['unit'],
            'qty' => $item['qty'],
            'done_qty' => $item['done_qty'],
            'balance_qty' => $item['balance_qty'],
            'percent' => $item['percent'],
        ];
    }

    public static function pendingEntry(ProgressEntry $entry): array
    {
        return [
            ...self::entry($entry),
            'item_code' => $entry->boqItem?->code,
            'item_description' => $entry->boqItem?->description,
            'unit' => $entry->boqItem?->unit,
            'floor_name' => $entry->floor ? ($entry->floor->parent?->name ? $entry->floor->parent->name.' › ' : '').$entry->floor->name : null,
        ];
    }

    public static function cell(MepStageCell $cell): array
    {
        return [
            'id' => $cell->id,
            'project_floor_id' => $cell->project_floor_id,
            'trade' => $cell->trade,
            'stage' => $cell->stage,
            'status' => $cell->status,
            'remark' => $cell->remark,
            'status_at' => $cell->status_at?->toIso8601String(),
            'updated_by' => $cell->updated_by,
        ];
    }

    /** The stage lists, so the app draws the same columns as the office screen. */
    public static function stages(array $trades, array $statuses): array
    {
        $result = [];

        foreach ($trades as $key => $trade) {
            $stages = [];
            $number = 0;

            foreach ($trade['stages'] as $stageKey => $stage) {
                $stages[] = [
                    'key' => $stageKey,
                    'no' => ++$number,
                    'label' => __($stage['label']),
                    'hint' => isset($stage['hint']) ? __($stage['hint']) : null,
                    'optional' => (bool) ($stage['optional'] ?? false),
                ];
            }

            $result[] = ['key' => $key, 'label' => __($trade['label']), 'stages' => $stages];
        }

        return ['trades' => $result, 'statuses' => collect($statuses)->map(fn (string $label, string $key) => ['key' => $key, 'label' => __($label)])->values()->all()];
    }

    public static function floorRef(ProjectFloor $floor): array
    {
        return ['id' => $floor->id, 'name' => $floor->name, 'parent_id' => $floor->parent_id];
    }
}
