<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuePhoto extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = ['before', 'after'];

    protected $guarded = [];

    // A new photo must reach devices on the next pull, which watches issues.updated_at.
    protected $touches = ['issue'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
