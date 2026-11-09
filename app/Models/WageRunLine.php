<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One worker's pay in a wage run. Frozen with its run. */
class WageRunLine extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'run';

    protected $guarded = [];

    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::$event(function (self $line) {
                $run = WageRun::withoutGlobalScopes()->find($line->wage_run_id);

                if ($run?->isLocked()) {
                    throw new ImmutableRecordException;
                }
            });
        }
    }

    protected function casts(): array
    {
        return [
            'daily_wage' => 'decimal:2',
            'present_days' => 'decimal:3',
            'half_days' => 'decimal:3',
            'absent_days' => 'decimal:3',
            'payable_days' => 'decimal:3',
            'ot_hours' => 'decimal:3',
            'day_pay' => 'decimal:2',
            'ot_pay' => 'decimal:2',
            'gross' => 'decimal:2',
            'advance_recovery' => 'decimal:2',
            'net' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WageRun::class, 'wage_run_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class)->withTrashed();
    }
}
