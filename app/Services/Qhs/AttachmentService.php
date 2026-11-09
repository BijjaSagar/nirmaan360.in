<?php

namespace App\Services\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\FieldAttachment;
use App\Models\Ncr;
use App\Models\QualityTest;
use App\Models\Rfi;
use App\Models\SafetyIncident;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Photos and documents on NCRs, incidents, RFIs and quality tests. Files go to the private
 * disk and are served only through a signed-in, permission-checked route, as DPR photos are.
 */
class AttachmentService
{
    use CreatesOnce;

    /** URL segment => [model, permission prefix]. Viewing needs "<prefix>.view", adding needs "<prefix>.manage". */
    public const OWNERS = [
        'ncrs' => [Ncr::class, 'qhs'],
        'incidents' => [SafetyIncident::class, 'safety'],
        'rfis' => [Rfi::class, 'rfi'],
        'quality-tests' => [QualityTest::class, 'qhs'],
    ];

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('modules.qhs.attachment_max_kb')],
            'caption' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** The permission prefix for a model class, or null if attachments are not allowed on it. */
    public static function prefixFor(string $modelClass): ?string
    {
        foreach (self::OWNERS as [$class, $prefix]) {
            if ($class === $modelClass) {
                return $prefix;
            }
        }

        return null;
    }

    /** Store a file. A repeated client_uuid returns the original, even on a record closed since. */
    public function store(Model $owner, UploadedFile $file, array $attributes, User $user): FieldAttachment
    {
        $uuid = $attributes['client_uuid'] ?? null;
        $existing = $this->find($owner->organization_id, $uuid);

        if ($existing) {
            return $existing;
        }

        if ($this->isLocked($owner)) {
            throw new ImmutableRecordException(__('This record is closed, so nothing can be added to it.'));
        }

        $path = $file->store('org-'.$owner->organization_id.'/qhs/'.$owner->getTable().'/'.$owner->getKey());

        $attachment = $this->createOnce(FieldAttachment::class, $owner->organization_id, $uuid, fn () => $owner->attachments()->create([
            'organization_id' => $owner->organization_id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'caption' => $attributes['caption'] ?? null,
            'uploaded_by' => $user->id,
            'client_uuid' => $uuid,
        ]));

        if (! $attachment->wasRecentlyCreated) {
            Storage::delete($path);
        }

        return $attachment;
    }

    private function find(int $organizationId, ?string $uuid): ?FieldAttachment
    {
        return filled($uuid)
            ? FieldAttachment::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first()
            : null;
    }

    private function isLocked(Model $owner): bool
    {
        return method_exists($owner, 'isClosed') && $owner->isClosed();
    }
}
