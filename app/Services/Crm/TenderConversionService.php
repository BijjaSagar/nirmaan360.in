<?php

namespace App\Services\Crm;

use App\Models\CrmTender;
use App\Models\CrmTenderBoqItem;
use App\Models\CrmTenderDocument;
use App\Models\Project;
use App\Models\User;
use App\Services\BoqService;
use App\Services\ProjectService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * FR-CRM-05: turn a won tender into a project, carrying its BOQ and documents.
 * The project goes through ProjectService and each BOQ line through BoqService, so plan limits,
 * generated codes and audit rows behave as they do everywhere else.
 */
class TenderConversionService
{
    /** Fixed namespace for the per-line ids handed to BoqService. */
    private const BOQ_NAMESPACE = '6f1d2b7e-3c41-4a58-9e0a-5b7c9d2e8f10';

    public function __construct(
        private ProjectService $projects,
        private BoqService $boq,
        private TenderBoqService $tenderBoq,
        private ActivityService $activities,
    ) {}

    public function rules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => [
                'nullable', 'string', 'max:32', 'alpha_dash',
                Rule::unique('projects', 'code')->where('organization_id', $user->organization_id),
            ],
            'contract_value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * What the confirmation screen shows and pre-fills.
     *
     * @return array{name:string,contract_value:string,boq_lines:int,boq_total:string,documents:int,emd_held:string}
     */
    public function preview(CrmTender $tender): array
    {
        $boqTotal = $this->tenderBoq->total($tender);

        $candidates = [$tender->awarded_value, $boqTotal, $tender->bid_value, $tender->estimated_value];
        $contractValue = collect($candidates)->first(fn ($value) => $value !== null && Dec::of($value)->isPositive()) ?? '0';

        $held = $tender->payments()->where('status', 'paid')->where('refundable', true)->get()
            ->reduce(fn ($sum, $payment) => $sum->plus(Dec::of($payment->amount)), Dec::of(0));

        return [
            'name' => $tender->title,
            'contract_value' => Dec::money($contractValue),
            'boq_lines' => $tender->boqItems()->count(),
            'boq_total' => $boqTotal,
            'documents' => $tender->documents()->count(),
            'emd_held' => Dec::money($held),
        ];
    }

    /**
     * Create the project, or return the one this tender already became. Calling it again, or two
     * people clicking at once, never makes a second project: the tender row is locked while it runs
     * and every BOQ line carries an id that BoqService treats as "already added".
     *
     * @return array{project:Project,created:bool,boq_lines:int,documents:int}
     */
    public function convert(CrmTender $tender, array $data, User $user): array
    {
        return DB::transaction(function () use ($tender, $data, $user) {
            $tender = CrmTender::query()->where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($tender->id);

            if ($tender->project_id && ($existing = Project::query()->where('organization_id', $user->organization_id)->find($tender->project_id))) {
                return ['project' => $existing, 'created' => false, 'boq_lines' => 0, 'documents' => 0];
            }

            if ($tender->stage !== 'won') {
                throw ValidationException::withMessages(['tender' => __('Only a won tender can be converted to a project.')]);
            }

            $project = $this->projects->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'client_id' => $tender->party_id,
                'location' => $tender->location,
                'state' => $tender->state,
                'contract_value' => $data['contract_value'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
            ], $user);

            $lines = $tender->boqItems()->get();

            foreach ($lines as $line) {
                $this->boq->create($project, [
                    'section' => $line->section,
                    'code' => $line->code,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'qty' => $line->qty,
                    'rate' => $line->rate,
                    'client_uuid' => $this->lineUuid($line, $project),
                ]);
            }

            // Documents follow the project: they now show under its Tender file page.
            $documents = CrmTenderDocument::query()
                ->where('organization_id', $tender->organization_id)
                ->where('tender_id', $tender->id)
                ->update(['project_id' => $project->id]);

            $tender->update(['project_id' => $project->id, 'converted_at' => now(), 'converted_by' => $user->id]);

            $this->activities->log($tender, 'system', __('Converted to project :code with :lines BOQ lines and :documents documents', [
                'code' => $project->code,
                'lines' => $lines->count(),
                'documents' => $documents,
            ]), $user);

            return ['project' => $project, 'created' => true, 'boq_lines' => $lines->count(), 'documents' => $documents];
        });
    }

    /** Same line and project always give the same id, so BoqService returns the line it already made. */
    private function lineUuid(CrmTenderBoqItem $line, Project $project): string
    {
        return Uuid::uuid5(self::BOQ_NAMESPACE, $line->uuid.'|'.$project->id)->toString();
    }
}
