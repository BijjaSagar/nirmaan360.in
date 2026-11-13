<?php

namespace App\Services\VoiceDpr;

use App\Models\BoqItem;
use App\Models\DprVoiceDraft;
use App\Models\Project;
use App\Services\DocumentReading\UnitText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The BOQ lines a spoken report is offered, as refs B1, B2 and so on: their code, description and
 * unit, never a rate. They are chosen once, when the report arrives, and kept on the draft by id, so
 * the request and the check of the answer always see the same list.
 *
 * Runs on the queue as well as in requests, so every query names the company.
 */
class VoiceContext
{
    /**
     * Ids of the lines to offer, B1 first: lines whose code or description shares a word with what was
     * said (see words() and same()), the closest first, then lines used in the project's DPRs of the
     * last days.
     *
     * @return list<int>
     */
    public function refsFor(Project $project, string $transcript): array
    {
        $figures = (array) config('modules.voice_dpr.context');
        $quiet = $this->quietWords();
        $numbers = [new SpokenNumbers('mr-IN'), new SpokenNumbers('hi-IN')];
        $said = $this->words($transcript, $quiet, $numbers);
        $recent = $this->recentlyUsed($project, (int) $figures['recent_days']);

        $items = BoqItem::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->siteWork()
            ->get(['id', 'code', 'description', 'sort']);

        return $items
            ->map(function (BoqItem $item) use ($said, $recent, $quiet, $numbers) {
                $own = $this->words($item->code.' '.$item->description, $quiet, $numbers);
                $score = count(array_filter($said, fn (string $word) => $this->shares($word, $own)));

                return ['id' => (int) $item->id, 'score' => $score, 'recent' => isset($recent[$item->id]), 'sort' => (int) $item->sort];
            })
            ->filter(fn (array $row) => $row['score'] > 0 || $row['recent'])
            ->sort(fn (array $a, array $b) => [$b['score'], $b['recent'], $a['sort'], $a['id']] <=> [$a['score'], $a['recent'], $b['sort'], $b['id']])
            ->take((int) $figures['items'])
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * The offered lines that are still on the project, by ref. A line deleted since keeps its ref
     * free, so the other refs never move.
     *
     * @return array<string, BoqItem>
     */
    public function items(DprVoiceDraft $draft): array
    {
        $ids = array_values(array_filter((array) ($draft->checks['refs'] ?? []), 'is_int'));

        if ($ids === []) {
            return [];
        }

        $found = BoqItem::withoutGlobalScopes()
            ->where('organization_id', $draft->organization_id)
            ->where('project_id', $draft->project_id)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'description', 'unit'])
            ->keyBy('id');

        $items = [];

        foreach ($ids as $index => $id) {
            if ($found->has($id)) {
                $items['B'.($index + 1)] = $found->get($id);
            }
        }

        return $items;
    }

    /** @return array<int, true> Lines named in the project's DPRs of the last days. */
    private function recentlyUsed(Project $project, int $days): array
    {
        return DB::table('dpr_work_items')
            ->join('dprs', 'dprs.id', '=', 'dpr_work_items.dpr_id')
            ->where('dprs.organization_id', $project->organization_id)
            ->where('dprs.project_id', $project->id)
            ->whereNull('dprs.deleted_at')
            ->where('dprs.report_date', '>=', now('Asia/Kolkata')->subDays($days)->toDateString())
            ->whereNotNull('dpr_work_items.boq_item_id')
            ->distinct()
            ->pluck('dpr_work_items.boq_item_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * The words that may name a line, in lower case, from what was said or from a line's code and
     * description alike: words with at least the configured number of letters that are not a stop
     * word, a unit, a size word or a number word. A unit, a size or a number says how much or how big,
     * never which line, and "मिमी" is left out as "mm" is.
     *
     * Letters are counted as \p{L}. In Devanagari that counts the consonants and vowels that begin a
     * syllable, not the vowel signs and the anusvara (\p{M}), so a word of two syllables such as भिंत,
     * वीट or छत has two letters. Such a word names work as fully as a longer English one, so a word
     * in Devanagari needs min_letters_devanagari letters; the one-syllable particles (है, का, ला) stay
     * out, and the stop words take out the rest.
     *
     * @param  array<string, true>  $quiet
     * @param  list<SpokenNumbers>  $numbers
     * @return list<string>
     */
    private function words(string $text, array $quiet, array $numbers): array
    {
        $minimum = (int) config('modules.voice_dpr.context.min_letters');
        $devanagari = (int) config('modules.voice_dpr.context.min_letters_devanagari');
        $long = fn (string $word) => self::letters($word) >= (preg_match('/\p{Devanagari}/u', $word) === 1 ? $devanagari : $minimum);
        $number = function (string $word) use ($numbers): bool {
            foreach ($numbers as $reader) {
                if ($reader->couldBePart($word)) {
                    return true;
                }
            }

            return false;
        };

        return (new Collection(preg_split('/[^\p{L}\p{M}\p{N}]+/u', SpokenNumbers::lower($text)) ?: []))
            ->filter(fn (string $word) => $long($word) && ! isset($quiet[$word]) && ! isset($quiet[UnitText::key($word)]) && ! $number($word))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, true> The stop words, the size words and every spelling of a unit, in lower case. */
    private function quietWords(): array
    {
        $quiet = [];

        foreach ([...(array) config('modules.voice_dpr.context_stop_words'), ...(array) config('modules.voice_dpr.size_words')] as $word) {
            $quiet[SpokenNumbers::lower((string) $word)] = true;
        }

        foreach (array_keys(UnitText::words()) as $unit) {
            $quiet[(string) $unit] = true;
        }

        return $quiet;
    }

    /** @param  list<string>  $own */
    private function shares(string $word, array $own): bool
    {
        foreach ($own as $candidate) {
            if ($this->same($word, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a spoken word and a word of the line are the same word, allowing for an ending on either:
     * "काँक्रीटचे" said for "काँक्रीट", "plaster" for "plastering".
     *
     * A word of fewer than min_letters letters (a Devanagari word of two syllables) is too short to be
     * read inside any longer word: "पर" would be found in "परवा" and "तार" in "तारीख". It matches itself,
     * or itself with vowel signs and at most one case ending from context_endings: "भिंतीचं" for भिंत,
     * "ईंटों" for ईंट. The short words that name no work (वर, पर, सह, कर) are stop words on both sides.
     */
    private function same(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        [$short, $long] = strlen($a) < strlen($b) ? [$a, $b] : [$b, $a];

        if (! str_starts_with($long, $short)) {
            return false;
        }

        if (self::letters($short) >= (int) config('modules.voice_dpr.context.min_letters')) {
            return true;
        }

        $ending = (string) preg_replace('/^\p{M}+/u', '', substr($long, strlen($short)));

        return $ending === '' || in_array($ending, array_map(fn ($end) => SpokenNumbers::lower((string) $end), (array) config('modules.voice_dpr.context_endings')), true);
    }

    private static function letters(string $word): int
    {
        return (int) preg_match_all('/\p{L}/u', $word);
    }
}
