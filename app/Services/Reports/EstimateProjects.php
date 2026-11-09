<?php

namespace App\Services\Reports;

use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Creates projects from estimate sheets for a company, for support loading a customer's existing estimates. */
final class EstimateProjects
{
    public function __construct(private BoqExcelService $excel) {}

    /**
     * A project whose code already exists is reused, so loading the same estimates again changes nothing.
     * The plan's project limit is not applied here: only someone with console access can run this.
     *
     * @param  array{code:string,name:string,client?:?string,location?:?string,state?:?string,grid:array<int, array<int, mixed>>}  $estimate
     * @return array{project:Project,result:BoqImportResult,value:string}
     */
    public function load(User $owner, array $estimate): array
    {
        return DB::transaction(function () use ($owner, $estimate) {
            $project = Project::where('code', $estimate['code'])->first() ?? $this->create($owner, $estimate);
            $result = $this->excel->importGrid($project, $estimate['grid']);

            if (! $result->ok()) {
                $problems = collect($result->errors)->map(fn (array $error) => 'row '.$error['row'].': '.$error['message'])->implode('; ');

                throw new RuntimeException($estimate['name'].': '.$problems);
            }

            $value = $project->boqItems()->get()->reduce(fn (BigDecimal $sum, $item) => $sum->plus($item->amount()), BigDecimal::zero());
            $project->update(['contract_value' => Dec::money($value)]);

            return ['project' => $project, 'result' => $result, 'value' => Dec::money($value)];
        });
    }

    private function create(User $owner, array $estimate): Project
    {
        $client = filled($estimate['client'] ?? null)
            ? Party::firstOrCreate(['organization_id' => $owner->organization_id, 'type' => 'client', 'name' => $estimate['client']])
            : null;

        return Project::create([
            'organization_id' => $owner->organization_id,
            'code' => $estimate['code'],
            'name' => $estimate['name'],
            'client_id' => $client?->id,
            'location' => $estimate['location'] ?? null,
            'state' => $estimate['state'] ?? null,
            'status' => 'active',
            'created_by' => $owner->id,
        ]);
    }
}
