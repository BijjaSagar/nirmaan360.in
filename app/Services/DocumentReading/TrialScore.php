<?php

namespace App\Services\DocumentReading;

use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;

/**
 * How many cells a dry run returned and how many of them the platform admin marked as wrong, by
 * field and by the script of the printed digits. Only counts are kept: they stay on the trial
 * after its files and what the model returned have been deleted.
 *
 * No accuracy figure is worked out here or shown anywhere. The counts are what was marked on the
 * documents that were tried, nothing more.
 */
final class TrialScore
{
    /** The mark for a whole answer, when it has no cells or is wrong as a whole. */
    public const WHOLE_ANSWER = '*';

    /**
     * Counts again from every page that still has its answer, and saves the result on the trial.
     *
     * @return array{answers_total: int, answers_wrong: int, cells_total: int, cells_wrong: int, fields: list<array{pass: string, field: string, cells_total: int, cells_wrong: int}>, scripts: array<string, array{cells_total: int, cells_wrong: int}>}
     */
    public static function refresh(ReaderTrial $trial): array
    {
        $score = ['answers_total' => 0, 'answers_wrong' => 0, 'cells_total' => 0, 'cells_wrong' => 0, 'fields' => [], 'scripts' => []];
        $fields = [];

        $pages = ReaderTrialPage::query()->where('reader_trial_id', $trial->id)->where('status', ReaderTrialPage::READ)
            ->whereNotNull('result')->orderBy('page_no')->orderBy('id')->get();

        foreach ($pages as $page) {
            $marked = array_flip(array_filter((array) $page->marks, is_string(...)));

            $score['answers_total']++;
            $score['answers_wrong'] += isset($marked[TrialCells::key(self::WHOLE_ANSWER, '')]) ? 1 : 0;

            foreach (TrialCells::of($page->result) as $cell) {
                $wrong = isset($marked[TrialCells::key($cell['field'], $cell['row'])]) ? 1 : 0;
                $name = $page->pass."\n".$cell['field'];

                $fields[$name] ??= ['pass' => (string) $page->pass, 'field' => $cell['field'], 'cells_total' => 0, 'cells_wrong' => 0];
                $fields[$name]['cells_total']++;
                $fields[$name]['cells_wrong'] += $wrong;

                $score['scripts'][$cell['script']] ??= ['cells_total' => 0, 'cells_wrong' => 0];
                $score['scripts'][$cell['script']]['cells_total']++;
                $score['scripts'][$cell['script']]['cells_wrong'] += $wrong;

                $score['cells_total']++;
                $score['cells_wrong'] += $wrong;
            }
        }

        ksort($fields);
        ksort($score['scripts']);
        $score['fields'] = array_values($fields);

        $trial->forceFill(['score' => $score])->save();

        return $score;
    }

    /**
     * The counts as rows for a spreadsheet.
     *
     * @return list<list<string|int>>
     */
    public static function rows(ReaderTrial $trial): array
    {
        $score = (array) $trial->score;
        $rows = [['group', 'pass', 'name', 'total', 'marked_wrong']];

        $rows[] = ['answers', '', 'all', (int) ($score['answers_total'] ?? 0), (int) ($score['answers_wrong'] ?? 0)];
        $rows[] = ['cells', '', 'all', (int) ($score['cells_total'] ?? 0), (int) ($score['cells_wrong'] ?? 0)];

        foreach ((array) ($score['fields'] ?? []) as $field) {
            $rows[] = ['field', (string) ($field['pass'] ?? ''), (string) ($field['field'] ?? ''), (int) ($field['cells_total'] ?? 0), (int) ($field['cells_wrong'] ?? 0)];
        }

        foreach ((array) ($score['scripts'] ?? []) as $script => $counts) {
            $rows[] = ['digit_script', '', (string) $script, (int) ($counts['cells_total'] ?? 0), (int) ($counts['cells_wrong'] ?? 0)];
        }

        return $rows;
    }
}
