<?php

namespace App\Http\Presenters;

use App\Models\Asset;
use App\Models\MachineLog;

final class MachineLogPresenter
{
    public static function asset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'code' => $asset->code,
            'name' => $asset->name,
            'category' => $asset->category,
            'ownership' => $asset->ownership,
            'registration_no' => $asset->registration_no,
            'meter_unit' => $asset->meter_unit,
            'project_id' => $asset->project_id,
            'site_id' => $asset->site_id,
            'status' => $asset->status,
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];
    }

    public static function log(MachineLog $log): array
    {
        return [
            'id' => $log->id,
            'client_uuid' => $log->client_uuid,
            'asset_id' => $log->asset_id,
            'project_id' => $log->project_id,
            'log_date' => $log->log_date?->toDateString(),
            'opening_reading' => $log->opening_reading,
            'closing_reading' => $log->closing_reading,
            'hours' => $log->hours,
            'km' => $log->km,
            'breakdown_hours' => $log->breakdown_hours,
            'operator' => $log->operator,
            'remarks' => $log->remarks,
            'billed' => $log->isBilled(),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
