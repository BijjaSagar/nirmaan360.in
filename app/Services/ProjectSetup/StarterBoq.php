<?php

namespace App\Services\ProjectSetup;

use App\Models\BoqItem;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The standard items of a building BOQ, in the order work happens. Only the names, units and
 * kinds of work are filled in. Quantity and rate are left at zero: they are the company's own
 * figures for this project, and nobody else decides them.
 */
class StarterBoq
{
    public const MODES = ['detailed', 'thumb_rule'];

    /** @var list<array{0:string,1:string,2:string}> Description, unit, category. */
    public const DETAILED = [
        ['Excavation', 'cum', 'excavation'],
        ['Filling', 'cum', 'filling'],
        ['PCC (plain cement concrete)', 'cum', 'pcc'],
        ['Foundation concreting', 'cum', 'foundation'],
        ['Steel reinforcement', 'kg', 'steel'],
        ['RCC: footings, columns, beams, slabs and chajjas', 'cum', 'rcc'],
        ['Brick work', 'cum', 'brick_work'],
        ['Plaster', 'sqm', 'plaster'],
        ['Flooring and tiles', 'sqm', 'flooring'],
        ['Plumbing (all stages)', 'LS', 'plumbing'],
        ['Electrical (all stages)', 'LS', 'electrical'],
        ['Painting and finishing', 'sqm', 'finishing'],
    ];

    /** A thumb-rule BOQ prices whole heads of work per square foot of built-up area. */
    public const THUMB_RULE = [
        ['Structure: excavation to RCC and steel', 'sqft', 'rcc'],
        ['Brick work and plaster', 'sqft', 'brick_work'],
        ['Flooring and tiles', 'sqft', 'flooring'],
        ['Plumbing and sanitary ware', 'sqft', 'plumbing'],
        ['Electrical', 'sqft', 'electrical'],
        ['Painting and finishing', 'sqft', 'finishing'],
    ];

    /** @return list<array{0:string,1:string,2:string}> */
    public function template(Project $project): array
    {
        return $project->boq_mode === 'thumb_rule' ? self::THUMB_RULE : self::DETAILED;
    }

    public function setMode(Project $project, string $mode): Project
    {
        $project->update(['boq_mode' => $mode]);

        return $project;
    }

    /**
     * Add the template to an empty BOQ. The project row is locked, so two taps at once cannot
     * add it twice.
     *
     * @return Collection<int, BoqItem>
     */
    public function apply(Project $project): Collection
    {
        return DB::transaction(function () use ($project) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            if ($project->boqItems()->exists()) {
                throw ValidationException::withMessages(['boq' => __('The starter template can only be added to an empty BOQ.')]);
            }

            return BoqRevisions::with(__('Starter template'), 'starter', function () use ($project) {
                return collect($this->template($project))->map(fn (array $row, int $index) => BoqItem::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'code' => (string) ($index + 1),
                    'description' => __($row[0]),
                    'unit' => $row[1],
                    'category' => $row[2],
                    'qty' => '0.000',
                    'rate' => '0.00',
                    'sort' => $index + 1,
                ]));
            }, batch: true);
        });
    }
}
