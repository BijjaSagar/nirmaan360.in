<?php

namespace Tests\Feature\BoqImport\Corpus;

use App\Services\Imports\SheetLayout;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * The whole of what the BOQ sheet reader made of one corpus case, written as plain text, line by line: the
 * heading and what each column was read as, every row's kind, every line's code, section, description, unit,
 * quantity, rate and where its code came from, the adjustments, the totals and whether they match, the
 * problems and the notes. A corpus case is this text, so any change to how a case is read shows as a diff.
 */
final class CorpusRender
{
    /** How much of a description or a row's text is written. */
    private const HEAD = 60;

    public static function render(array $read, SheetLayout $layout, ?array $file = null): string
    {
        $out = [];

        if ($file !== null) {
            $sheets = array_map(fn (array $sheet, int $index) => $sheet['name'].($sheet['hidden'] ? ' (hidden)' : '').($index === $file['sheet'] ? ' *' : ''), $file['sheets'], array_keys($file['sheets']));
            $out[] = 'file: '.$file['format'].' | sheets: '.implode(', ', $sheets).' | picked: '.$file['picked'];
        }

        if (! $layout->found()) {
            $out[] = 'heading: not found';
        } else {
            $out[] = 'heading: row '.$layout->row.($layout->span > 1 ? ' to '.$layout->lastRow() : '');
            $columns = [];

            foreach ($layout->fields as $column => $field) {
                if ($field !== null) {
                    $unit = isset($layout->units[$column]) ? ' ['.$layout->units[$column].']' : '';
                    $columns[] = Coordinate::stringFromColumnIndex($column).'='.$field.'('.($layout->how[$column] ?? '?').')'.$unit;
                }
            }

            $out[] = 'columns: '.implode(' ', $columns);
        }

        $out[] = 'rows:';

        foreach ($read['kinds'] as $row => $kind) {
            $line = $read['lines'][$row] ?? null;

            if ($line !== null) {
                $parts = [
                    $line['code'],
                    $line['section'] ?? '-',
                    self::head((string) $line['description']),
                    $line['unit'] === '' ? '-' : $line['unit'],
                    'qty '.$line['qty'],
                    'rate '.$line['rate'],
                    $line['origin'] ?? '?',
                ];

                if (($line['per'] ?? null) !== null) {
                    $parts[] = 'per '.$line['per'];
                }

                $out[] = sprintf('  %d %s | %s', $row, $kind['kind'], implode(' | ', $parts));
            } else {
                $out[] = sprintf('  %d %s | %s', $row, $kind['kind'], self::head($kind['text']));
            }
        }

        $out[] = sprintf('lines: %d | lines total %s | with adjustments %s | sheet subtotal %s | sheet total %s',
            count($read['lines']), $read['lines_total'], $read['with_adjustments'], $read['file_subtotal'] ?? '-', $read['file_total'] ?? '-');

        foreach ($read['adjustments'] as $adjustment) {
            $out[] = sprintf('adjustment: %d | %s | %s%% | comes to %s | sheet %s | %s',
                $adjustment['row'], self::head($adjustment['label']), $adjustment['percent'] ?? '-', $adjustment['effect'], $adjustment['amount'] ?? '-', $adjustment['shows'] ?? '-');
        }

        foreach ($read['totals'] as $total) {
            $out[] = sprintf('total: %d | %s | %s | sheet %s | lines %s | %s',
                $total['row'], $total['kind'], self::head($total['label']), $total['amount'] ?? '-', $total['adjusted'] ? $total['with_adjustments'] : $total['lines'],
                $total['matches'] === null ? 'no figure' : ($total['matches'] ? 'matches' : 'does not match'));
        }

        foreach ($read['errors'] as $error) {
            $out[] = sprintf('problem: %d %s | %s', $error['row'], $error['field'] ?? '-', $error['message']);
        }

        foreach ($read['notes'] as $note) {
            $out[] = sprintf('note: %d | %s', $note['row'], $note['message']);
        }

        return implode("\n", $out)."\n";
    }

    private static function head(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), self::HEAD, '…');
    }
}
