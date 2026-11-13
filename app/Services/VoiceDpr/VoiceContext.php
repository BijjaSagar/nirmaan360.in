<?php

namespace App\Services\VoiceDpr;

use App\Models\BoqItem;
use App\Models\DprVoiceDraft;
use App\Models\Project;
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
     * Ids of the lines to offer, B1 first: lines whose code or description shares a word of three or
     * more letters with what was said, the closest first, then lines used in the project's DPRs of
     * the last days.
     *
     * @return list<int>
     */
    public function refsFor(Project $project, string $transcript): array
    {
        $figures = (array) config('modules.voice_dpr.context');
        $said = $this->words($transcript, true);
        $recent = $this->recentlyUsed($project, (int) $figures['recent_days']);

        $items = BoqItem::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->siteWork()
            ->get(['id', 'code', 'description', 'sort']);

        return $items
            ->map(function (BoqItem $item) use ($said, $recent) {
                $own = $this->words($item->code.' '.$item->description, false);
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
     * Words in lower case with at least the configured number of letters. For what was said, the
     * words that name no work are left out.
     *
     * @return list<string>
     */
    private function words(string $text, bool $spoken): array
    {
        $minimum = (int) config('modules.voice_dpr.context.min_letters');
        $stop = $spoken ? array_map(fn ($word) => SpokenNumbers::lower((string) $word), (array) config('modules.voice_dpr.context_stop_words')) : [];

        return (new Collection(preg_split('/[^\p{L}\p{M}\p{N}]+/u', SpokenNumbers::lower($text)) ?: []))
            ->filter(fn (string $word) => preg_match_all('/\p{L}/u', $word) >= $minimum && ! in_array($word, $stop, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a spoken word and a word of the line are the same word, allowing for an ending on either:
     * "काँक्रीटचे" said for "काँक्रीट", "plaster" for "plastering".
     *
     * @param  list<string>  $own
     */
    private function shares(string $word, array $own): bool
    {
        foreach ($own as $candidate) {
            if (str_starts_with($candidate, $word) || str_starts_with($word, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
