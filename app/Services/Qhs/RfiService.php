<?php

namespace App\Services\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\Approval;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\FieldOps\CreatesOnce;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Requests for information. A draft goes through the approval engine as the "rfi" document type
 * before it is sent out. After approval the question is locked: the answer and closing follow.
 */
class RfiService
{
    use CreatesOnce;

    private const FIELDS = ['subject', 'question', 'drawing_ref', 'location', 'addressed_to', 'priority', 'required_by'];

    public function __construct(private ApprovalService $approvals) {}

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:160'],
            'question' => ['required', 'string', 'max:5000'],
            'drawing_ref' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'addressed_to' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', Rule::in(config('buildos.issue_priorities'))],
            'required_by' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function responseRules(): array
    {
        return [
            'response' => ['required', 'string', 'max:5000'],
            'responded_on' => ['required', 'date', 'before_or_equal:today'],
            'responded_by' => ['nullable', 'string', 'max:120'],
            'cost_impact' => ['nullable', 'boolean'],
            'time_impact_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    /** Draft an RFI. A repeated client_uuid returns the original row. */
    public function create(Project $project, array $data, User $user): Rfi
    {
        return $this->createOnce(Rfi::class, $project->organization_id, $data['client_uuid'] ?? null, function () use ($project, $data, $user) {
            $this->lockProject($project);
            $seq = $this->nextSequence(Rfi::class, 'project_id', $project->id);

            return Rfi::create([
                ...Arr::only($data, self::FIELDS),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'seq' => $seq,
                'number' => sprintf('RFI-%s-%03d', $project->code, $seq),
                'status' => 'DRAFT',
                'raised_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
        });
    }

    /** Only a draft or a returned RFI can be edited. */
    public function update(Rfi $rfi, array $data): Rfi
    {
        abort_unless($rfi->isEditable(), 409, __('Only a draft or returned RFI can be changed.'));

        $rfi->update(Arr::only($data, self::FIELDS));

        return $rfi;
    }

    /** Send for approval. The chain is the company's "rfi" rule, or the default approver roles. */
    public function submit(Rfi $rfi, User $user): ?Approval
    {
        $configured = $this->approvals->levelsFor('rfi', $rfi->organization_id);

        return $this->approvals->submit($rfi, $user, $configured === [] ? config('modules.qhs.rfi_default_levels') : []);
    }

    /** Record the answer on an approved RFI. */
    public function respond(Rfi $rfi, array $data): Rfi
    {
        $this->assertOpenForAnswer($rfi);

        if ($rfi->created_at->copy()->startOfDay()->gt($data['responded_on'])) {
            throw ValidationException::withMessages(['responded_on' => __('The answer cannot be dated before the RFI was raised.')]);
        }

        $rfi->update([
            'response' => $data['response'],
            'responded_on' => $data['responded_on'],
            'responded_by' => $data['responded_by'] ?? null,
            'cost_impact' => (bool) ($data['cost_impact'] ?? false),
            'time_impact_days' => $data['time_impact_days'] ?? null,
        ]);

        return $rfi;
    }

    public function close(Rfi $rfi, User $user): Rfi
    {
        $this->assertOpenForAnswer($rfi);

        if ($rfi->response === null) {
            throw ValidationException::withMessages(['response' => __('Record the answer before closing the RFI.')]);
        }

        $rfi->update(['closed_at' => now(), 'closed_by' => $user->id]);

        return $rfi;
    }

    private function assertOpenForAnswer(Rfi $rfi): void
    {
        if ($rfi->status !== 'APPROVED') {
            throw ValidationException::withMessages(['status' => __('The RFI has to be approved before it can be answered.')]);
        }

        if ($rfi->isClosed()) {
            throw new ImmutableRecordException(__('A closed RFI cannot be changed.'));
        }
    }
}
