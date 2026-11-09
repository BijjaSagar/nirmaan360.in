<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One specimen: scheduled for an age, then tested and given a strength. */
class QualityTestResult extends Model
{
    use Auditable, BelongsToOrganization;

    public const PROJECT_VIA = 'test';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'tested_on' => 'date',
            'load_kn' => 'decimal:2',
            'strength' => 'decimal:2',
        ];
    }

    public function isTested(): bool
    {
        return $this->strength !== null;
    }

    public function isDue(): bool
    {
        return ! $this->isTested() && $this->due_on->lte(today());
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(QualityTest::class, 'quality_test_id');
    }
}
