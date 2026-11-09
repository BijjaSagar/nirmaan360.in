<?php

namespace App\Services\SiteProgress;

use App\Models\MepStageCell;
use App\Models\ProgressEntry;
use App\Models\ProgressPhoto;
use App\Models\User;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Photos of work done or of a problem, kept on the private disk and read only through a checked route. */
class ProgressPhotoService
{
    public const MAX_PER_RECORD = 10;

    /** Rules for one uploaded photo. */
    public function rules(string $field = 'photo'): array
    {
        return [
            $field => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
            'taken_at' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** A repeated client_uuid returns the original photo, so a phone can retry an upload safely. */
    public function attach(ProgressEntry|MepStageCell $target, UploadedFile $file, User $user, array $attributes = []): ProgressPhoto
    {
        $uuid = $attributes['client_uuid'] ?? null;
        $key = $target instanceof ProgressEntry ? 'progress_entry_id' : 'mep_stage_cell_id';

        if ($uuid && ($existing = $this->find($target->organization_id, $uuid))) {
            return $existing->{$key} === $target->id
                ? $existing
                : throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        if (ProgressPhoto::where($key, $target->id)->count() >= self::MAX_PER_RECORD) {
            throw ValidationException::withMessages(['photo' => __('At most :count photos can be kept here.', ['count' => self::MAX_PER_RECORD])]);
        }

        $path = $file->store('org-'.$target->organization_id.'/progress/'.$target->project_id);

        $photo = Idempotent::create(
            fn () => ProgressPhoto::create([
                'organization_id' => $target->organization_id,
                'project_id' => $target->project_id,
                $key => $target->id,
                'path' => $path,
                'caption' => $attributes['caption'] ?? null,
                'taken_at' => $attributes['taken_at'] ?? null,
                'uploaded_by' => $user->id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->find($target->organization_id, (string) $uuid),
        );

        if (! $photo->wasRecentlyCreated) {
            Storage::delete($path);
        }

        return $photo;
    }

    /** Attach a list of uploaded files (the web forms send several at once). */
    public function attachMany(ProgressEntry|MepStageCell $target, array $files, User $user): int
    {
        $count = 0;

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $this->attach($target, $file, $user);
                $count++;
            }
        }

        return $count;
    }

    private function find(int $organizationId, string $uuid): ?ProgressPhoto
    {
        return ClientUuidRepeat::reachable(ProgressPhoto::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
