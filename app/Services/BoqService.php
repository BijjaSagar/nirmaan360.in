<?php

namespace App\Services;

use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\Project;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

class BoqService
{
    /** The web form's rules, where a code is required. */
    public function rules(): array
    {
        return [
            'section' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:2000'],
            'unit' => ['required', 'string', 'max:16'],
            'qty' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'rate' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    /**
     * Add a line at the end of the BOQ. A repeated client_uuid returns the original row.
     * The project row lock serialises additions, so sort and a generated code cannot be raced.
     */
    public function create(Project $project, array $data): BoqItem
    {
        return DB::transaction(function () use ($project, $data) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findByClientUuid($project->organization_id, $uuid))) {
                return $existing;
            }

            $section = $this->sectionId($project, $data['section'] ?? null);
            unset($data['section']);

            return BoqItem::create([
                ...$data,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'boq_section_id' => $section,
                'code' => filled($data['code'] ?? null) ? $data['code'] : $this->nextCode($project),
                'qty' => Dec::qty($data['qty']),
                'rate' => Dec::money($data['rate']),
                'sort' => (int) $project->boqItems()->max('sort') + 1,
            ]);
        });
    }

    /** Find or create a section by name within the project. A blank name means no section. */
    public function sectionId(Project $project, ?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return BoqSection::firstOrCreate(
            ['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $name],
        )->id;
    }

    /** One above the highest plain number or sort position. Deleted lines keep theirs, so no number is reused. */
    private function nextCode(Project $project): string
    {
        $lines = BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->get(['code', 'sort']);

        $numbers = $lines->pluck('code')
            ->filter(fn (string $code) => preg_match('/^\d{1,15}$/', $code) === 1)
            ->map(fn (string $code) => (int) $code);

        return (string) ($numbers->push((int) $lines->max('sort'))->max() + 1);
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    private function findByClientUuid(int $organizationId, string $uuid): ?BoqItem
    {
        return ClientUuidRepeat::reachable(BoqItem::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
