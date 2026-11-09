<?php

namespace App\Services\SiteAlerts;

use App\Models\BoqItem;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which BOQ lines belong to a trade such as RCC or brick work. A line with a category (set by
 * project_setup) belongs by category alone; a line with none is matched on words in its section
 * name, code and description (config/modules/site_alerts.php, focus_trades).
 */
class TradeMatcher
{
    /** @var array<int, Collection<int, BoqItem>> */
    private array $items = [];

    /**
     * @param  array{category?: ?string, match?: list<string>}  $trade
     * @return Collection<int, BoqItem>
     */
    public function items(Project $project, array $trade): Collection
    {
        $category = $trade['category'] ?? null;
        $words = array_filter(array_map(fn (string $word) => mb_strtolower(trim($word)), $trade['match'] ?? []));

        return $this->all($project)->filter(function (BoqItem $item) use ($category, $words) {
            if (filled($item->category)) {
                return $category !== null && $item->category === $category;
            }

            $text = mb_strtolower(($item->section?->name ?? '').' '.$item->code.' '.$item->description);

            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** @return Collection<int, BoqItem> */
    private function all(Project $project): Collection
    {
        return $this->items[$project->id] ??= BoqItem::with('section')->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get();
    }
}
