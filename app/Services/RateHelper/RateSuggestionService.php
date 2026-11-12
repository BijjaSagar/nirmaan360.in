<?php

namespace App\Services\RateHelper;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Models\User;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use Illuminate\Validation\Rule;

/** Starts a run: the lines a person picked on a project's BOQ, each with its shortlist. */
class RateSuggestionService
{
    public function __construct(private RateSources $sources, private Shortlist $shortlist) {}

    public function maxLines(): int
    {
        return (int) config('modules.rate_helper.max_lines', 100);
    }

    /** The picked lines must be live lines of this project's BOQ. */
    public function rules(Project $project): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'boq_item_ids' => ['required', 'array', 'min:1', 'max:'.$this->maxLines()],
            'boq_item_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('boq_items', 'id')
                    ->where('organization_id', $project->organization_id)
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'boq_item_ids.required' => __('Choose at least one BOQ line.'),
            'boq_item_ids.min' => __('Choose at least one BOQ line.'),
            'boq_item_ids.max' => __('Choose at most :max BOQ lines at a time.', ['max' => $this->maxLines()]),
            'boq_item_ids.*.exists' => __('One of these items is not in this project.'),
        ];
    }

    /**
     * Builds the shortlist for every picked line, here in the request and as the signed-in person,
     * and saves it. A repeated client_uuid returns the run made the first time.
     *
     * @param  list<int|string>  $itemIds
     */
    public function start(Project $project, User $viewer, array $itemIds, string $clientUuid): RateSuggestionRun
    {
        if ($existing = $this->findByClientUuid($project->organization_id, $clientUuid)) {
            return $existing;
        }

        $items = BoqItem::query()
            ->where('project_id', $project->id)
            ->whereIn('id', array_map('intval', $itemIds))
            ->orderBy('sort')->orderBy('id')
            ->get();

        $sources = $this->sources->open();
        $lists = [];

        foreach ($items as $item) {
            $lists[$item->id] = $this->shortlist->for($sources, $viewer, $item);
        }

        return Idempotent::create(function () use ($project, $viewer, $clientUuid, $items, $lists) {
            $run = RateSuggestionRun::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'user_id' => $viewer->id,
                'status' => RateSuggestionRun::OPEN,
                'client_uuid' => $clientUuid,
            ]);

            foreach ($items as $item) {
                RateSuggestionLine::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'run_id' => $run->id,
                    'boq_item_id' => $item->id,
                    'candidates' => $lists[$item->id],
                ]);
            }

            return $run;
        }, fn () => $this->findByClientUuid($project->organization_id, $clientUuid));
    }

    /** One key belongs to one run across the whole company, so the lookup is company-wide too. A run in a project this person cannot reach is an error, not a second insert. */
    private function findByClientUuid(int $organizationId, string $clientUuid): ?RateSuggestionRun
    {
        return ClientUuidRepeat::reachable(
            RateSuggestionRun::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $clientUuid)->first()
        );
    }
}
