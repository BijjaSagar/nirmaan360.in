<?php

namespace App\Services\BillingExtras;

use App\Models\Issue;
use App\Models\IssuePhoto;
use App\Models\User;
use App\Support\Idempotent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IssuePhotoService
{
    /** Rules for one photo, shared by the web form and the REST API. */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(IssuePhoto::KINDS)],
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
            'taken_at' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Rules for the before photos sent along with a new issue. */
    public function listRules(): array
    {
        return [
            'photos' => ['array', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /** Store a photo privately. A repeated client_uuid returns the original row. */
    public function add(Issue $issue, UploadedFile $file, array $attributes, User $user): IssuePhoto
    {
        $uuid = $attributes['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($issue, $uuid))) {
            return $existing;
        }

        $path = $file->store('org-'.$issue->organization_id.'/issues/'.$issue->id);

        $photo = Idempotent::create(
            fn () => $issue->photos()->create([
                'organization_id' => $issue->organization_id,
                'kind' => $attributes['kind'],
                'path' => $path,
                'caption' => $attributes['caption'] ?? null,
                'taken_at' => $attributes['taken_at'] ?? null,
                'uploaded_by' => $user->id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->find($issue, (string) $uuid),
        );

        if (! $photo->wasRecentlyCreated) {
            Storage::delete($path);
        }

        return $photo;
    }

    public function remove(IssuePhoto $photo): void
    {
        $photo->delete();
        Storage::delete($photo->path);
    }

    private function find(Issue $issue, string $uuid): ?IssuePhoto
    {
        $photo = IssuePhoto::withoutGlobalScopes()->where('organization_id', $issue->organization_id)->where('client_uuid', $uuid)->first();

        if ($photo && $photo->issue_id !== $issue->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another issue.')]);
        }

        return $photo;
    }
}
