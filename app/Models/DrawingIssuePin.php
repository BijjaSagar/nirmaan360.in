<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrawingIssuePin extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['x' => 'decimal:3', 'y' => 'decimal:3'];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DrawingSheet::class, 'drawing_sheet_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
