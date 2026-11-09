<?php

namespace App\Services\Crm;

use App\Models\CrmActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ActivityService
{
    /** Rules for the "log a note, call or meeting" form. */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'in:'.implode(',', Subjects::types())],
            'subject_id' => ['required', 'integer'],
            'kind' => ['required', 'in:'.implode(',', CrmActivity::MANUAL_KINDS)],
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    public function log(Model $subject, string $kind, string $body, ?User $user = null): CrmActivity
    {
        return CrmActivity::create([
            'organization_id' => $subject->getAttribute('organization_id'),
            'subject_type' => Subjects::typeOf($subject),
            'subject_id' => $subject->getKey(),
            'kind' => $kind,
            'body' => $body,
            'user_id' => $user?->id,
            'happened_at' => now(),
        ]);
    }

    /** Log by the ids from a form, refusing a record that belongs to another company. */
    public function logFor(User $user, string $type, int $id, string $kind, string $body): CrmActivity
    {
        $subject = Subjects::find($type, $id, $user->organization_id);

        if (! $subject) {
            throw ValidationException::withMessages(['subject_id' => __('That record was not found.')]);
        }

        return $this->log($subject, $kind, $body, $user);
    }

    /** @return Collection<int,CrmActivity> Newest first. */
    public function timeline(Model $subject, int $limit = 50): Collection
    {
        return CrmActivity::withoutGlobalScopes()
            ->with('user')
            ->where('organization_id', $subject->getAttribute('organization_id'))
            ->where('subject_type', Subjects::typeOf($subject))
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('happened_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
