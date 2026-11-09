<?php

namespace App\Http\Presenters;

use App\Models\Site;

final class SitePresenter
{
    public static function present(Site $site): array
    {
        return [
            'id' => $site->id,
            'project_id' => $site->project_id,
            'name' => $site->name,
            'address' => $site->address,
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }
}
