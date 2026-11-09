<?php

namespace App\Http\Presenters;

use App\Models\Attendance;

final class AttendancePresenter
{
    public static function present(Attendance $row): array
    {
        return [
            'id' => $row->id,
            'client_uuid' => $row->client_uuid,
            'project_id' => $row->project_id,
            'worker_id' => $row->worker_id,
            'attendance_date' => $row->attendance_date?->toDateString(),
            'status' => $row->status,
            'ot_hours' => $row->ot_hours,
            'marked_by' => $row->marked_by,
            'client_updated_at' => $row->client_updated_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
