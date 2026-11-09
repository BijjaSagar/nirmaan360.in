<?php

namespace App\Http\Presenters;

use App\Models\Worker;

final class WorkerPresenter
{
    public static function present(Worker $worker): array
    {
        return [
            'id' => $worker->id,
            'name' => $worker->name,
            'phone' => $worker->phone,
            'trade' => $worker->trade,
            'type' => $worker->type,
            'is_active' => (bool) $worker->is_active,
            'updated_at' => $worker->updated_at?->toIso8601String(),
        ];
    }
}
