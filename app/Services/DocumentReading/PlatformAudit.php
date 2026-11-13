<?php

namespace App\Services\DocumentReading;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * An audit row for something the platform's own staff did that belongs to no company, such as a
 * dry run of a reader on a test document.
 *
 * AuditLog::record cannot write these. It files a row under the model's company or, failing that,
 * under the signed-in person's, and the model itself fills an empty company from the signed-in
 * person as it is created. Either way the row would land in the audit trail of the admin's own
 * company, where that company's owner could read it. So the row is written here without the
 * model's events, with no company at all. No company's audit screen shows it.
 */
final class PlatformAudit
{
    /**
     * @param  ?array<string, mixed>  $new  Counts, codes, sizes and ids only: never a document's content or a file name.
     * @param  ?User  $actor  Who did it, when nobody is signed in.
     */
    public static function record(string $action, Model $model, ?array $new = null, ?User $actor = null, ?array $old = null): AuditLog
    {
        return AuditLog::withoutEvents(fn () => AuditLog::withoutGlobalScopes()->create([
            'organization_id' => null,
            'user_id' => $actor?->id ?? Auth::id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => Request::ip(),
        ]));
    }
}
