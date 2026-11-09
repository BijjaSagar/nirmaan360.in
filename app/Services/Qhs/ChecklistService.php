<?php

namespace App\Services\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Project;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reusable checklists and the inspections filled in from them: pour card, shuttering, PPE and so on.
 * An inspection copies the template's items when it starts, so editing a template later does not
 * change inspections already made. Completing one locks it as passed or failed.
 */
class ChecklistService
{
    use CreatesOnce;

    /** Templates a company can add in one click. The wording is a starting point to edit. */
    public const STANDARD = [
        [
            'name' => 'Pour card',
            'category' => 'pour_card',
            'items' => [
                'Pour location and element checked against the approved drawing',
                'Shuttering checked for line, level, plumb and rigidity',
                'Reinforcement checked and approved: size, spacing, laps and cover',
                'Cover blocks fixed and enough in number',
                'Sleeves, conduits and embedded items in position',
                'Formwork clean, free of debris and binding wire cuttings',
                'Release agent applied to the shuttering',
                'Concrete grade and mix confirmed with the supplier',
                'Slump or flow tested and recorded',
                'Cubes cast and marked for 7 and 28 days',
                'Vibrators and a standby unit ready and working',
                'Pour sequence and construction joint locations agreed',
                'Curing arrangement ready',
            ],
        ],
        [
            'name' => 'Shuttering check',
            'category' => 'shuttering',
            'items' => [
                'Props and bracing as per the scheme, base plates firm',
                'Joints tight, with no leakage gaps',
                'Line, level and plumb checked with instruments',
                'Soffit level and camber as per the drawing',
                'Tie rods, spacers and cover blocks in place',
                'Surface clean and release agent applied',
                'Openings and block-outs fixed',
                'Stripping time noted for removal',
            ],
        ],
        [
            'name' => 'Reinforcement check',
            'category' => 'reinforcement',
            'items' => [
                'Bar diameter and grade as per the drawing',
                'Spacing and number of bars as per the drawing',
                'Lap lengths and anchorage as per the drawing',
                'Cover as per the drawing',
                'Bar bending schedule matches the fixed steel',
                'Binding wire tight and ends turned inward',
                'No loose rust, oil or mud on the bars',
            ],
        ],
        [
            'name' => 'PPE checklist',
            'category' => 'ppe',
            'items' => [
                'Safety helmets worn by everyone on site',
                'Safety shoes worn',
                'High-visibility vests worn',
                'Gloves used for handling steel and cement',
                'Eye protection used for cutting and grinding',
                'Ear protection used near noisy work',
                'Safety harness used for work at height',
                'Dust masks used for dusty work',
            ],
        ],
        [
            'name' => 'Scaffolding safety',
            'category' => 'safety',
            'items' => [
                'Base plates and sole boards firm and level',
                'Ties to the structure at every lift',
                'Platform boards complete and fixed',
                'Guard rails and toe boards in place',
                'Safe ladder access provided',
                'Inspected and tagged as safe to use',
            ],
        ],
        [
            'name' => 'Excavation safety',
            'category' => 'safety',
            'items' => [
                'Sides sloped or shored for the depth and soil',
                'Edge barricading and warning signs in place',
                'Safe access and exit ladders provided',
                'Excavated soil kept back from the edge',
                'Water pumped out and ground checked after rain',
                'Underground services located and marked',
            ],
        ],
    ];

    public function templateRules(User $user, ?ChecklistTemplate $template = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('checklist_templates', 'name')->where('organization_id', $user->organization_id)->ignore($template?->id)],
            'category' => ['required', Rule::in(array_keys(config('modules.qhs.checklist_categories')))],
            'description' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function startRules(Project $project, User $user): array
    {
        return [
            'checklist_template_id' => ['bail', 'required', 'integer', Rule::exists('checklist_templates', 'id')->where('organization_id', $user->organization_id)->where('is_active', true)],
            'title' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            'site_id' => ['bail', 'nullable', 'integer', $this->siteOfProjectRule($project->id)],
            'inspected_on' => ['nullable', 'date', 'before_or_equal:today'],
            'results' => ['nullable', 'array', 'max:60'],
            'results.*.position' => ['required_with:results', 'integer', 'min:1', 'max:60'],
            'results.*.result' => ['required_with:results', Rule::in(array_keys(config('modules.qhs.inspection_results')))],
            'results.*.remark' => ['nullable', 'string', 'max:255'],
            'complete' => ['nullable', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function saveRules(Inspection $inspection): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.id' => ['required', 'integer', Rule::exists('inspection_items', 'id')->where('inspection_id', $inspection->id)],
            'items.*.result' => ['required', Rule::in(array_keys(config('modules.qhs.inspection_results')))],
            'items.*.remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** One item per line, blank lines dropped. */
    public static function parseItems(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), fn (string $line) => $line !== ''));
    }

    public function createTemplate(array $data, User $user): ChecklistTemplate
    {
        return $this->createOnce(ChecklistTemplate::class, $user->organization_id, $data['client_uuid'] ?? null, fn () => ChecklistTemplate::create([
            'organization_id' => $user->organization_id,
            'name' => $data['name'],
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
            'items' => array_values($data['items']),
            'is_active' => true,
            'created_by' => $user->id,
            'client_uuid' => $data['client_uuid'] ?? null,
        ]));
    }

    /** Inspections already made keep the items they were started with. */
    public function updateTemplate(ChecklistTemplate $template, array $data): ChecklistTemplate
    {
        $template->update([
            'name' => $data['name'],
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
            'items' => array_values($data['items']),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return $template;
    }

    /** Add the standard templates the company does not have yet. Returns how many were added. */
    public function installStandard(User $user): int
    {
        $added = 0;

        foreach (self::STANDARD as $standard) {
            $exists = ChecklistTemplate::where('organization_id', $user->organization_id)->where('name', $standard['name'])->exists();

            if (! $exists) {
                ChecklistTemplate::create([
                    'organization_id' => $user->organization_id,
                    'name' => $standard['name'],
                    'category' => $standard['category'],
                    'items' => $standard['items'],
                    'is_active' => true,
                    'created_by' => $user->id,
                ]);
                $added++;
            }
        }

        return $added;
    }

    /**
     * Start an inspection from a template. Results may come with it, by item position, so a phone that
     * worked offline can send the whole thing at once. A repeated client_uuid returns the original and applies nothing.
     */
    public function start(Project $project, array $data, User $user): Inspection
    {
        return $this->createOnce(Inspection::class, $project->organization_id, $data['client_uuid'] ?? null, function () use ($project, $data, $user) {
            $template = ChecklistTemplate::findOrFail($data['checklist_template_id']);

            $inspection = Inspection::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'site_id' => $data['site_id'] ?? null,
                'checklist_template_id' => $template->id,
                'title' => $data['title'] ?? $template->name,
                'category' => $template->category,
                'location' => $data['location'] ?? null,
                'inspected_on' => $data['inspected_on'] ?? today()->toDateString(),
                'status' => 'in_progress',
                'inspector_id' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            foreach (array_values($template->items) as $index => $text) {
                InspectionItem::create([
                    'organization_id' => $project->organization_id,
                    'inspection_id' => $inspection->id,
                    'sort' => $index + 1,
                    'text' => $text,
                ]);
            }

            $items = $inspection->items()->get();

            foreach ($data['results'] ?? [] as $row) {
                $item = $items->firstWhere('sort', (int) $row['position']);

                if (! $item) {
                    throw ValidationException::withMessages(['results' => __('Item :position is not on this checklist.', ['position' => $row['position']])]);
                }

                $this->setResult($item, $row['result'], $row['remark'] ?? null, 'results');
            }

            if ($data['complete'] ?? false) {
                $this->complete($inspection->refresh(), $user);
            }

            return $inspection;
        });
    }

    /** Save results for some or all items while the inspection is open. */
    public function save(Inspection $inspection, array $rows): Inspection
    {
        $this->assertOpen($inspection);

        DB::transaction(function () use ($inspection, $rows) {
            $items = $inspection->items()->get()->keyBy('id');

            foreach ($rows as $index => $row) {
                $this->setResult($items[(int) $row['id']], $row['result'], $row['remark'] ?? null, "items.$index.remark");
            }
        });

        return $inspection;
    }

    /** Finish the inspection. Every item needs a result. Any failed item makes it failed. */
    public function complete(Inspection $inspection, User $user): Inspection
    {
        $this->assertOpen($inspection);

        $items = $inspection->items()->get();
        $pending = $items->where('result', 'pending')->count();

        if ($pending > 0) {
            throw ValidationException::withMessages(['items' => __(':count item(s) still have no result.', ['count' => $pending])]);
        }

        $inspection->update([
            'status' => $items->contains('result', 'fail') ? 'failed' : 'passed',
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);

        return $inspection;
    }

    private function setResult(InspectionItem $item, string $result, ?string $remark, string $field): void
    {
        if ($result === 'fail' && blank($remark)) {
            throw ValidationException::withMessages([$field => __('Say what is wrong with a failed item.')]);
        }

        $item->update(['result' => $result, 'remark' => $remark]);
    }

    private function assertOpen(Inspection $inspection): void
    {
        if (! $inspection->isOpen()) {
            throw new ImmutableRecordException(__('A completed inspection cannot be changed.'));
        }
    }
}
