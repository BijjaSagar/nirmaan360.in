<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** One batch of samples (for example six cubes from a pour) and the results at each age. */
class QualityTest extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cast_date' => 'date',
            'required_strength' => 'decimal:2',
            'quantity_cum' => 'decimal:3',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(QualityTestResult::class)->orderBy('age_days')->orderBy('specimen_no');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(FieldAttachment::class, 'attachable');
    }
}
