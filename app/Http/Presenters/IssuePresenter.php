<?php

namespace App\Http\Presenters;

use App\Models\Issue;
use App\Models\IssuePhoto;

final class IssuePresenter
{
    public static function photo(IssuePhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'issue_id' => $photo->issue_id,
            'kind' => $photo->kind,
            'caption' => $photo->caption,
            'taken_at' => $photo->taken_at?->toIso8601String(),
        ];
    }

    public static function present(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'client_uuid' => $issue->client_uuid,
            'project_id' => $issue->project_id,
            'site_id' => $issue->site_id,
            'title' => $issue->title,
            'description' => $issue->description,
            'priority' => $issue->priority,
            'status' => $issue->status,
            'location_note' => $issue->location_note,
            'assigned_to' => $issue->assigned_to,
            'raised_by' => $issue->raised_by,
            'due_date' => $issue->due_date?->toDateString(),
            'resolved_at' => $issue->resolved_at?->toIso8601String(),
            'client_updated_at' => $issue->client_updated_at?->toIso8601String(),
            'updated_at' => $issue->updated_at?->toIso8601String(),
        ];
    }
}
