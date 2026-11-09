<?php

namespace App\Services\Drawings;

use App\Models\DrawingIssuePin;
use App\Models\DrawingSheet;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IssuePinService
{
    public function __construct(private IssueService $issues) {}

    /** The issue form's own rules, with the title optional when an existing issue is chosen instead. */
    public function rules(DrawingSheet $sheet, User $user): array
    {
        $project = Project::findOrFail($sheet->project_id);

        return [
            ...$this->issues->rules($project, $user),
            'title' => ['required_without:issue_id', 'nullable', 'string', 'max:160'],
            'priority' => ['required_without:issue_id', 'nullable', Rule::in(config('buildos.issue_priorities'))],
            'issue_id' => ['bail', 'nullable', 'integer', Rule::exists('issues', 'id')->where('project_id', $sheet->project_id)->whereNull('deleted_at')],
            'x' => ['required', 'numeric', 'min:0'],
            'y' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Pin a new or existing issue to a point on a sheet. An issue has one pin, so pinning it again moves it.
     * A repeated client_uuid returns the original pin.
     */
    public function pin(DrawingSheet $sheet, array $data, User $user): DrawingIssuePin
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($sheet->organization_id, $uuid))) {
            return $existing;
        }

        [$x, $y] = $this->position($sheet, $data['x'], $data['y']);

        return DB::transaction(function () use ($sheet, $data, $user, $uuid, $x, $y) {
            $issue = ! empty($data['issue_id'])
                ? Issue::findOrFail($data['issue_id'])
                : $this->issues->create(Project::findOrFail($sheet->project_id), $this->issueFields($data), $user);

            $pin = DrawingIssuePin::firstOrNew(['issue_id' => $issue->id]);

            $pin->fill([
                'organization_id' => $sheet->organization_id,
                'project_id' => $sheet->project_id,
                'drawing_sheet_id' => $sheet->id,
                'x' => $x,
                'y' => $y,
            ]);

            if (! $pin->exists) {
                $pin->created_by = $user->id;
                $pin->client_uuid = $uuid;
            }

            $pin->save();

            return $pin;
        });
    }

    /** Removes the pin only. The issue itself stays open on the issues screen. */
    public function remove(DrawingIssuePin $pin): void
    {
        $pin->delete();
    }

    /** The shape the viewer needs for one pin. */
    public function present(DrawingIssuePin $pin): array
    {
        $issue = $pin->issue;

        return [
            'id' => $pin->id,
            'issue_id' => $pin->issue_id,
            'x' => $pin->x,
            'y' => $pin->y,
            'title' => $issue?->title,
            'status' => $issue?->status,
            'priority' => $issue?->priority,
            'assignee' => $issue?->assignee?->name,
            'due_date' => $issue?->due_date?->format('d M Y'),
        ];
    }

    private function issueFields(array $data): array
    {
        return collect($data)->only(['title', 'description', 'priority', 'location_note', 'assigned_to', 'due_date', 'site_id', 'client_uuid'])->all();
    }

    /** @return array{0:string,1:string} */
    private function position(DrawingSheet $sheet, mixed $x, mixed $y): array
    {
        $x = Dec::of(is_string($x) ? trim($x) : $x);
        $y = Dec::of(is_string($y) ? trim($y) : $y);

        if ($x->isGreaterThan(Dec::of($sheet->width)) || $y->isGreaterThan(Dec::of($sheet->height))) {
            throw ValidationException::withMessages(['x' => __('A point is outside the sheet.')]);
        }

        return [Dec::qty($x), Dec::qty($y)];
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?DrawingIssuePin
    {
        return DrawingIssuePin::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
