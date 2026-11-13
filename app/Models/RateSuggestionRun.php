<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One press of "Suggest rates" on a project's BOQ. It has a project_id, so a person limited to
 * chosen projects never reaches a run of another project.
 */
class RateSuggestionRun extends Model
{
    use Auditable, BelongsToOrganization;

    /** Lines still wait for a person. */
    public const OPEN = 'open';

    /** Every line was accepted or skipped. */
    public const DONE = 'done';

    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RateSuggestionLine::class, 'run_id')->orderBy('id');
    }
}
