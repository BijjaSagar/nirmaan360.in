<?php

namespace App\Services\Imports;

use App\Models\BoqImportLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The BOQ file layouts a company has confirmed, so the next file laid out the same way maps itself.
 * A layout is found by the signature of its heading row; it belongs to one company and is audited.
 */
final class BoqImportLayouts
{
    /** At most this many layouts are compared with a new file. The most recently used come first. */
    private const COMPARED = 300;

    /**
     * The saved layout that matches one of these sheets, with the sheet and the heading row it matches.
     *
     * @param  list<array{name: string, grid: array<int, array<int, mixed>>, merges: list<array{0:int,1:int,2:int,3:int}>}>  $sheets
     * @return array{layout: BoqImportLayout, sheet: int, row: int, span: int}|null
     */
    public function find(int $organizationId, array $sheets): ?array
    {
        $detector = new BoqHeadings;
        $bySheet = [];

        foreach ($sheets as $index => $sheet) {
            $bySheet[$index] = $detector->signatures($sheet['grid'], $sheet['merges']);
        }

        $all = array_merge(...array_map('array_keys', array_values($bySheet ?: [[]])));

        if ($all === []) {
            return null;
        }

        $layouts = BoqImportLayout::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('signature', array_slice(array_unique($all), 0, 2000))
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->limit(self::COMPARED)
            ->get()
            ->keyBy('signature');

        if ($layouts->isEmpty()) {
            return null;
        }

        // The sheet the layout was saved from first, then any sheet, top row first.
        $order = array_keys($sheets);

        foreach ($layouts as $layout) {
            usort($order, fn (int $a, int $b) => ($sheets[$b]['name'] === $layout->sheet_name) <=> ($sheets[$a]['name'] === $layout->sheet_name) ?: $a <=> $b);

            foreach ($order as $index) {
                if (isset($bySheet[$index][$layout->signature])) {
                    [$row, $span] = $bySheet[$index][$layout->signature];

                    return ['layout' => $layout, 'sheet' => $index, 'row' => $row, 'span' => $span];
                }
            }
        }

        return null;
    }

    /** Remember how a file was read, or count another use of a layout already saved. */
    public function save(int $organizationId, User $user, SheetLayout $layout, string $sheetName, ?string $unit, string $fileName): BoqImportLayout
    {
        $saved = BoqImportLayout::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('signature', $layout->signature())
            ->first();

        $values = [
            'headings' => $layout->headings,
            'fields' => $layout->fields,
            'span' => $layout->span,
            'sheet_name' => Str::limit($sheetName, 60, ''),
            'unit' => $unit === null ? null : Str::limit($unit, 16, ''),
            'last_used_at' => now(),
        ];

        if ($saved !== null) {
            $saved->update([...$values, 'uses' => $saved->uses + 1]);

            return $saved;
        }

        return BoqImportLayout::create([
            ...$values,
            'organization_id' => $organizationId,
            'signature' => $layout->signature(),
            'file_name' => Str::limit(basename($fileName), 160, ''),
            'uses' => 1,
            'created_by' => $user->id,
        ]);
    }

    /** @return Collection<int, BoqImportLayout> The company's layouts, most recently used first. */
    public function forOrganization(int $organizationId): Collection
    {
        return BoqImportLayout::where('organization_id', $organizationId)
            ->with('creator:id,name')
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function delete(BoqImportLayout $layout): void
    {
        $layout->delete();
    }

    /** @return array<string, string> What a column can hold, named for the screen. */
    public static function fieldLabels(): array
    {
        return [
            'serial' => __('Serial number'),
            'code' => __('Item code'),
            'description' => __('Description'),
            'unit' => __('Unit'),
            'qty' => __('Quantity'),
            'rate' => __('Rate'),
            'amount' => __('Amount'),
            'remark' => __('Remarks'),
            'section' => __('Section'),
            'category' => __('Category'),
            'details' => __('Details'),
            'reference' => __('Reference (kept in details)'),
        ];
    }
}
