<?php

namespace App\Services\ProjectSetup;

use App\Models\Project;
use App\Models\ProjectPermit;
use App\Models\ProjectPermitFile;
use App\Models\User;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The permission checklist of a project: what is needed, from whom, what it costs and where it stands. */
class PermitService
{
    /**
     * The fee is money, so it is accepted only from someone who may see the budget. For anyone
     * else it is left out of the rules, and the saved fee stays as it is.
     */
    public function rules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'authority' => ['nullable', 'string', 'max:160'],
            'documents_required' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(ProjectPermit::STATUSES)],
            'applied_on' => ['nullable', 'date'],
            'approved_on' => ['nullable', 'date', 'after_or_equal:applied_on'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            // Sent by the add form, so pressing save twice adds one permission.
            'client_uuid' => ['nullable', 'uuid'],
            ...($user->can('money.overview') ? ['fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2']] : []),
        ];
    }

    public function fileRules(): array
    {
        return ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:10240']];
    }

    /** Add a permission. A repeated client_uuid returns the original. */
    public function create(Project $project, array $data): ProjectPermit
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? ClientUuidRepeat::reachable(ProjectPermit::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first()) : null;

        if ($existing = $find()) {
            return $this->sameProject($existing, $project);
        }

        return $this->sameProject(Idempotent::create(fn () => DB::transaction(function () use ($project, $data) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            return ProjectPermit::create([
                ...$this->dated($data),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'sort' => (int) ProjectPermit::where('project_id', $project->id)->max('sort') + 1,
            ]);
        }), $find), $project);
    }

    public function update(ProjectPermit $permit, array $data): ProjectPermit
    {
        unset($data['client_uuid']);

        $permit->update($this->dated($data, $permit));

        return $permit;
    }

    /** Removes the permit and its files. */
    public function delete(ProjectPermit $permit): void
    {
        $permit->delete();
    }

    public function addFile(ProjectPermit $permit, UploadedFile $file, User $user): ProjectPermitFile
    {
        $path = $file->store('org-'.$permit->organization_id.'/permits/'.$permit->id);

        try {
            return ProjectPermitFile::create([
                'organization_id' => $permit->organization_id,
                'project_id' => $permit->project_id,
                'project_permit_id' => $permit->id,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'uploaded_by' => $user->id,
            ]);
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }
    }

    public function removeFile(ProjectPermitFile $file): void
    {
        $file->delete();
    }

    /** The fees of every permit on the project, added exactly. A blank fee counts as nothing. */
    public function totalFee(Project $project): string
    {
        $total = BigDecimal::zero();

        foreach (ProjectPermit::where('project_id', $project->id)->whereNotNull('fee')->pluck('fee') as $fee) {
            $total = $total->plus(Dec::of($fee));
        }

        return Dec::money($total);
    }

    /** @return array{total:int,approved:int,applied:int,rejected:int,not_started:int} */
    public function counts(Project $project): array
    {
        $byStatus = ProjectPermit::where('project_id', $project->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            ...array_combine(ProjectPermit::STATUSES, array_map(fn (string $status) => (int) ($byStatus[$status] ?? 0), ProjectPermit::STATUSES)),
        ];
    }

    private function sameProject(ProjectPermit $permit, Project $project): ProjectPermit
    {
        if ($permit->project_id !== $project->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another project.')]);
        }

        return $permit;
    }

    /** Marking a permit applied or approved with no date takes today's, so the checklist is never half filled. */
    private function dated(array $data, ?ProjectPermit $permit = null): array
    {
        $status = $data['status'] ?? $permit?->status;
        $applied = array_key_exists('applied_on', $data) ? $data['applied_on'] : $permit?->applied_on;
        $approved = array_key_exists('approved_on', $data) ? $data['approved_on'] : $permit?->approved_on;

        if ($status === 'approved' && blank($approved)) {
            $data['approved_on'] = $approved = today()->toDateString();
        }

        if (in_array($status, ['applied', 'approved'], true) && blank($applied)) {
            $data['applied_on'] = blank($approved) ? today()->toDateString() : Carbon::parse($approved)->toDateString();
        }

        return $data;
    }
}
