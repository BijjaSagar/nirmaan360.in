<?php

namespace App\Services\SiteDiary;

use App\Models\Dpr;
use App\Models\Hindrance;
use App\Models\HindranceSource;
use App\Models\HindranceSuggestion;
use App\Models\Project;
use App\Services\DocumentReading\ReaderRequest;
use App\Services\DocumentReading\TextRequest;
use Illuminate\Support\Collection;
use Normalizer;

/**
 * The hindrance notes of daily reports as the AI is given them for grouping, and the same text again
 * when its answer is read, so that a quote from the answer is compared with exactly what was sent.
 *
 * Each note is the report's text under hindrances with phone numbers, e-mail addresses, GSTINs, PANs
 * and account numbers taken out, every "<" and ">" replaced by a look-alike, all spacing collapsed to
 * single spaces, and cut to a length set in the module's config. A site is named by a ref (S1, S2),
 * never by its name. Nothing here runs with a signed-in person, so every query names the company.
 */
class HindranceNotes
{
    /** How a report is named to the AI: D1, D2 and so on, in the order of the suggestion's dpr_ids. */
    public const REF = 'D';

    public const SITE_REF = 'S';

    /**
     * The reports a new suggestion would send: those of the project that say something under hindrances
     * and that no entry of the register cites (dismissed entries aside), in the dates asked for. When
     * there are more than one request may carry, the latest ones are taken. Oldest first.
     *
     * @return list<int> Report ids.
     */
    public function pick(Project $project, ?string $from = null, ?string $to = null): array
    {
        $cited = $this->cited($project);

        $reports = Dpr::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereNotNull('hindrances')
            ->where('hindrances', '!=', '')
            ->whereNotIn('id', $cited)
            ->when($from !== null, fn ($dprs) => $dprs->whereDate('report_date', '>=', $from))
            ->when($to !== null, fn ($dprs) => $dprs->whereDate('report_date', '<=', $to))
            ->orderByDesc('report_date')->orderByDesc('id')
            ->get(['id', 'report_date', 'hindrances'])
            ->filter(fn (Dpr $report) => Hindrances::saysSomething($report->hindrances))
            ->take(self::mostReports());

        return $reports->sortBy([['report_date', 'asc'], ['id', 'asc']])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return list<int> Reports that an entry of the register cites now, dismissed entries aside. */
    public function cited(Project $project): array
    {
        $ids = HindranceSource::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereIn('hindrance_id', Hindrance::query()
                ->where('organization_id', $project->organization_id)
                ->where('project_id', $project->id)
                ->where('status', '!=', Hindrance::DISMISSED)
                ->select('id'))
            ->pluck('dpr_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique($ids));
    }

    /**
     * The notes of a suggestion as they stand now, by ref. A report that was removed, moved to another
     * project or emptied since keeps its ref and is left out, so the other refs never shift.
     *
     * @return array<string, array{ref: string, report: Dpr, date: string, site: string, text: string}>
     */
    public function of(HindranceSuggestion $suggestion): array
    {
        $ids = $suggestion->reportIds();

        $reports = Dpr::query()
            ->where('organization_id', $suggestion->organization_id)
            ->where('project_id', $suggestion->project_id)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->get()
            ->keyBy('id');

        $sites = $this->siteRefs($ids, $reports);
        $notes = [];

        foreach ($ids as $index => $id) {
            $report = $reports->get($id);

            if ($report === null || ! Hindrances::saysSomething($report->hindrances)) {
                continue;
            }

            $text = self::sent((string) $report->hindrances);

            if ($text === '') {
                continue;
            }

            $ref = self::REF.($index + 1);
            $notes[$ref] = [
                'ref' => $ref,
                'report' => $report,
                'date' => $report->report_date->toDateString(),
                'site' => $report->site_id === null ? '' : ($sites[(int) $report->site_id] ?? ''),
                'text' => $text,
            ];
        }

        return $notes;
    }

    /**
     * The note text as the AI is sent it. Applied the same way when its answer is read.
     */
    public static function sent(string $hindrances): string
    {
        $text = TextRequest::withoutPersonalDetails(ReaderRequest::safe(self::collapsed($hindrances)));
        $text = mb_substr($text, 0, self::noteChars());

        // Cutting can leave a run of digits that now reads as a phone number: take that out too, as the request would.
        return trim(TextRequest::withoutPersonalDetails($text));
    }

    /**
     * Whether a quote from the answer stands in a note: the same characters, in the same order, once
     * spacing is collapsed on both sides. A quote must carry a letter, so a lone number or sign proves nothing.
     */
    public static function quoted(string $quote, string $note): bool
    {
        $quote = self::collapsed(ReaderRequest::safe($quote));

        if ($quote === '' || preg_match('/\p{L}/u', $quote) !== 1) {
            return false;
        }

        return str_contains(self::collapsed($note), $quote);
    }

    /** Text on one line with single spaces, in one Unicode form. */
    public static function collapsed(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        $text = class_exists(Normalizer::class) ? (Normalizer::normalize($text, Normalizer::FORM_C) ?: $text) : $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** The most reports one request carries. */
    public static function mostReports(): int
    {
        return max(1, (int) config('modules.site_diary.grouping.most_reports'));
    }

    /** The most characters of one note that are sent. */
    public static function noteChars(): int
    {
        return max(1, (int) config('modules.site_diary.grouping.note_chars'));
    }

    /**
     * S1, S2 and so on for the sites of the reports, numbered in the order the reports are sent.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, Dpr>  $reports
     * @return array<int, string> Site id => its ref.
     */
    private function siteRefs(array $ids, Collection $reports): array
    {
        $refs = [];

        foreach ($ids as $id) {
            $site = $reports->get($id)?->site_id;

            if ($site !== null && ! isset($refs[(int) $site])) {
                $refs[(int) $site] = self::SITE_REF.(count($refs) + 1);
            }
        }

        return $refs;
    }
}
