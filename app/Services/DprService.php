<?php

namespace App\Services;

use App\Exceptions\ImmutableRecordException;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Project;
use App\Models\User;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DprService
{
    private const FIELDS = ['site_id', 'report_date', 'weather', 'summary', 'hindrances', 'client_uuid', 'client_updated_at'];

    /** Request key => relation, for the three child lists a DPR carries. */
    private const CHILDREN = ['manpower' => 'manpower', 'machinery' => 'machinery', 'work' => 'workItems'];

    public function __construct(private ApprovalService $approvals) {}

    /** One rule set for the web form, the REST API and offline sync. */
    public function rules(Project $project): array
    {
        return [
            'report_date' => ['required', 'date', 'before_or_equal:today'],
            'site_id' => ['bail', 'nullable', 'integer', Rule::exists('sites', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'weather' => ['nullable', 'string', 'max:60'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'hindrances' => ['nullable', 'string', 'max:5000'],
            'client_uuid' => ['nullable', 'uuid'],
            'manpower' => ['array'],
            'manpower.*.trade' => ['nullable', 'string', 'max:60'],
            'manpower.*.count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'machinery' => ['array'],
            'machinery.*.name' => ['nullable', 'string', 'max:80'],
            'machinery.*.hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'work' => ['array'],
            'work.*.boq_item_id' => ['bail', 'nullable', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'work.*.description' => ['nullable', 'string', 'max:255'],
            'work.*.qty' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /** Create a draft. A repeated client_uuid returns the original row. */
    public function create(Project $project, array $data, User $user): Dpr
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($project->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(
            function () use ($project, $data, $user) {
                $dpr = Dpr::create([
                    ...Arr::only($data, self::FIELDS),
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'status' => 'DRAFT',
                    'created_by' => $user->id,
                ]);

                $this->saveChildren($dpr, $data);

                return $dpr;
            },
            fn () => $this->findByClientUuid($project->organization_id, (string) $uuid),
        );
    }

    /** Update fields and replace any child list present in $data. */
    public function update(Dpr $dpr, array $data): Dpr
    {
        if ($dpr->status === 'APPROVED') {
            throw new ImmutableRecordException;
        }

        abort_unless($dpr->isEditable(), 409, __('Only draft or returned DPRs can be changed.'));

        return DB::transaction(function () use ($dpr, $data) {
            // Without a client stamp, this edit's updated_at is what later sync writes compare against.
            $dpr->update(['client_updated_at' => null, ...Arr::only($data, self::FIELDS)]);

            $before = $this->childRows($dpr);

            foreach (self::CHILDREN as $key => $relation) {
                if (array_key_exists($key, $data)) {
                    $dpr->{$relation}()->delete();
                }
            }

            $this->saveChildren($dpr, $data);

            // Child rows are not Auditable, so a replacement is recorded against the DPR.
            if ($before !== ($after = $this->childRows($dpr))) {
                AuditLog::record('dpr.children_replaced', $dpr, $before, $after);
            }

            // Changing only child rows would otherwise be invisible to the next pull.
            $dpr->touch();

            return $dpr->unsetRelations();
        });
    }

    public function submit(Dpr $dpr, User $user): ?Approval
    {
        return $this->approvals->submit($dpr, $user);
    }

    /** Store a photo. A repeated client_uuid returns the original, even on a DPR approved since. */
    public function addPhoto(Dpr $dpr, UploadedFile $file, array $attributes = []): DprPhoto
    {
        $uuid = $attributes['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findPhoto($dpr->organization_id, $uuid))) {
            return $existing;
        }

        if ($dpr->status === 'APPROVED') {
            throw new ImmutableRecordException;
        }

        $path = $file->store('org-'.$dpr->organization_id.'/dprs/'.$dpr->id);

        $photo = Idempotent::create(
            fn () => $dpr->photos()->create([
                'organization_id' => $dpr->organization_id,
                'path' => $path,
                'caption' => $attributes['caption'] ?? null,
                'taken_at' => $attributes['taken_at'] ?? null,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findPhoto($dpr->organization_id, (string) $uuid),
        );

        if (! $photo->wasRecentlyCreated) {
            Storage::delete($path);
        }

        return $photo;
    }

    private function saveChildren(Dpr $dpr, array $data): void
    {
        $base = ['organization_id' => $dpr->organization_id];

        foreach ($data['manpower'] ?? [] as $row) {
            if (filled($row['trade'] ?? null) && (int) ($row['count'] ?? 0) > 0) {
                $dpr->manpower()->create([...$base, 'trade' => $row['trade'], 'count' => (int) $row['count']]);
            }
        }

        foreach ($data['machinery'] ?? [] as $row) {
            if (filled($row['name'] ?? null)) {
                $dpr->machinery()->create([...$base, 'name' => $row['name'], 'hours' => $row['hours'] ?? 0]);
            }
        }

        foreach ($data['work'] ?? [] as $row) {
            if (filled($row['boq_item_id'] ?? null) || filled($row['description'] ?? null)) {
                $dpr->workItems()->create([
                    ...$base,
                    'boq_item_id' => $row['boq_item_id'] ?? null,
                    'description' => $row['description'] ?? null,
                    'qty' => Dec::qty($row['qty'] ?? 0),
                ]);
            }
        }
    }

    private function childRows(Dpr $dpr): array
    {
        $ignored = ['id', 'organization_id', 'dpr_id', 'created_at', 'updated_at'];

        return array_map(
            fn (string $relation) => $dpr->{$relation}()->orderBy('id')->get()->map(fn ($row) => Arr::except($row->attributesToArray(), $ignored))->all(),
            self::CHILDREN,
        );
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    private function findByClientUuid(int $organizationId, string $uuid): ?Dpr
    {
        return ClientUuidRepeat::reachable(Dpr::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    private function findPhoto(int $organizationId, string $uuid): ?DprPhoto
    {
        return ClientUuidRepeat::reachable(DprPhoto::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
