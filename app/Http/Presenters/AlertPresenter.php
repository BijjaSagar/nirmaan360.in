<?php

namespace App\Http\Presenters;

use App\Models\Alert;

final class AlertPresenter
{
    /** Wording in the caller's language. Callers already filtered out alerts whose money permission the person lacks. */
    public static function present(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'project_id' => $alert->project_id,
            'project_name' => $alert->relationLoaded('project') ? $alert->project?->name : null,
            'type' => $alert->type,
            'reference' => $alert->reference,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'title' => $alert->titleIn(),
            'message' => $alert->messageIn(),
            'data' => $alert->data ?? [],
            'url' => $alert->url,
            'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
            'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
        ];
    }
}
