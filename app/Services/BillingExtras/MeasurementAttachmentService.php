<?php

namespace App\Services\BillingExtras;

use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Models\User;
use App\Support\Idempotent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeasurementAttachmentService
{
    /** A photo must be an image. A sketch may also be a scanned PDF. */
    public function rules(?string $kind): array
    {
        $mimes = $kind === 'sketch' ? 'jpg,jpeg,png,webp,pdf' : 'jpg,jpeg,png,webp';

        return [
            'kind' => ['required', Rule::in(MeasurementAttachment::KINDS)],
            'file' => ['required', 'file', 'mimes:'.$mimes, 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Store an attachment privately. A repeated client_uuid returns the original, even on an entry approved since. */
    public function add(MeasurementEntry $entry, UploadedFile $file, array $attributes, User $user): MeasurementAttachment
    {
        $uuid = $attributes['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($entry, $uuid))) {
            return $existing;
        }

        MeasurementAttachment::assertChangeable($entry->refresh());

        $path = $file->store('org-'.$entry->organization_id.'/measurements/'.$entry->id);

        $attachment = Idempotent::create(
            fn () => $entry->attachments()->create([
                'organization_id' => $entry->organization_id,
                'kind' => $attributes['kind'],
                'path' => $path,
                'caption' => $attributes['caption'] ?? null,
                'uploaded_by' => $user->id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->find($entry, (string) $uuid),
        );

        if (! $attachment->wasRecentlyCreated) {
            Storage::delete($path);
        }

        return $attachment;
    }

    /** The model refuses this once the entry is approved or under review, and removes the file. */
    public function remove(MeasurementAttachment $attachment): void
    {
        $attachment->delete();
    }

    private function find(MeasurementEntry $entry, string $uuid): ?MeasurementAttachment
    {
        $attachment = MeasurementAttachment::withoutGlobalScopes()->where('organization_id', $entry->organization_id)->where('client_uuid', $uuid)->first();

        if ($attachment && $attachment->measurement_entry_id !== $entry->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another measurement.')]);
        }

        return $attachment;
    }
}
