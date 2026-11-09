<?php

namespace App\Services\PriceWatch;

use Illuminate\Support\Arr;

/**
 * The board and project rows as the mobile API returns them: the same figures the web pages
 * show, without the model objects. Callers have already required the money permission, so
 * nothing here is blanked; a route without it answers 403 and never reaches this.
 */
final class PriceWatchPresenter
{
    private const BOARD = ['id', 'key', 'name', 'unit', 'material_id', 'is_active', 'current', 'previous', 'change_pct', 'trend', 'count'];

    private const PROJECT = ['boq_rate', 'boq_qty', 'difference', 'above_boq_pct', 'need', 'need_basis', 'received', 'remaining', 'impact', 'stale', 'why'];

    /** @param  array<string, mixed>  $row */
    public static function board(array $row): array
    {
        return Arr::only($row, self::BOARD);
    }

    /** @param  array<string, mixed>  $row */
    public static function project(array $row): array
    {
        return Arr::only($row, [...self::BOARD, ...self::PROJECT]);
    }

    /** @param  array<string, mixed>  $history  From PriceBoard::history() */
    public static function history(array $history): array
    {
        return [...self::board($history), 'prices' => $history['prices']];
    }
}
