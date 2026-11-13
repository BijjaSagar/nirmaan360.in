<?php

namespace App\Services\DocumentReading;

use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Services\DocumentReading\Contracts\DescribesTrialResult;
use Throwable;

/**
 * What the platform admin's result page shows for each call of a dry run: the cells as they were
 * read and as the code reads them, the kind's own checks by code, and how the call went.
 *
 * It is worked out from the stored answer each time the page is opened, with the same parsers a
 * company's read uses, and nothing of it is saved.
 */
class TrialResults
{
    public function __construct(private ReadKinds $kinds) {}

    /**
     * @return list<array{page: ReaderTrialPage, cells: list<array<string, mixed>>, marked: array<string, true>, whole_marked: bool, checks: list<array{label: string, ok: bool, note: ?string}>, blank: bool, failure: ?string, provider_note: ?string, answer: ?string}>
     */
    public function of(ReaderTrial $trial): array
    {
        $kind = $trial->isTextCall() ? null : $this->kinds->find((string) $trial->kind);
        $describes = $kind instanceof DescribesTrialResult;
        $pages = ReaderTrialPage::query()->where('reader_trial_id', $trial->id)->orderBy('page_no')->orderBy('id')->get();

        // Every pass read on a page so far, for the checks that compare one pass with another.
        $answers = [];

        foreach ($pages as $page) {
            if ($page->status === ReaderTrialPage::READ && is_array($page->result)) {
                $answers[(int) $page->page_no][(string) $page->pass] = $page->result;
            }
        }

        $rows = [];
        $checked = [];

        foreach ($pages as $page) {
            $answer = $page->status === ReaderTrialPage::READ && is_array($page->result) ? $page->result : null;
            $marked = array_fill_keys(array_filter((array) $page->marks, is_string(...)), true);
            $checks = [];
            $blank = false;

            if ($answer !== null && $kind !== null) {
                // A kind's own code must not break the admin's page. What it cannot answer is left out.
                try {
                    $blank = ! $kind->chargeable(TrialRunner::standIn($trial, $page, $answer));

                    // The checks of a page are shown once, with its last pass, when every pass read so far can be compared.
                    if ($describes && ! isset($checked[(int) $page->page_no]) && $page->is($pages->where('page_no', $page->page_no)->where('status', ReaderTrialPage::READ)->last())) {
                        $checks = $this->checks($kind->pageChecks($answers[(int) $page->page_no] ?? []));
                        $checked[(int) $page->page_no] = true;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            $rows[] = [
                'page' => $page,
                'cells' => $answer === null ? [] : TrialCells::of($answer, $describes ? $this->cellKinds($kind, (string) $page->pass) : []),
                'marked' => $marked,
                'whole_marked' => isset($marked[TrialCells::key(TrialScore::WHOLE_ANSWER, '')]),
                'checks' => $checks,
                'blank' => $blank,
                'failure' => $page->status === ReaderTrialPage::FAILED ? ReaderTrials::failureWords($page->fail_reason) : null,
                'provider_note' => $page->status === ReaderTrialPage::FAILED ? TrialRunner::rejectionNote((int) $page->id) : null,
                'answer' => $answer === null ? null : (string) json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function cellKinds(DescribesTrialResult $kind, string $pass): array
    {
        try {
            return array_filter($kind->cellKinds($pass), is_string(...));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  mixed  $checks  Whatever the kind returned.
     * @return list<array{label: string, ok: bool, note: ?string}>
     */
    private function checks(mixed $checks): array
    {
        $clean = [];

        foreach (is_array($checks) ? $checks : [] as $check) {
            if (is_array($check) && is_string($check['label'] ?? null)) {
                $clean[] = ['label' => $check['label'], 'ok' => (bool) ($check['ok'] ?? false), 'note' => is_string($check['note'] ?? null) ? $check['note'] : null];
            }
        }

        return $clean;
    }
}
