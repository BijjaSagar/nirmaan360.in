<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrawingSheet extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'calib_points' => 'array',
            'calib_length_m' => 'decimal:3',
            'metres_per_unit' => 'decimal:12',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'calibrated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DrawingVersion::class, 'drawing_version_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(TakeoffMeasurement::class);
    }

    public function pins(): HasMany
    {
        return $this->hasMany(DrawingIssuePin::class);
    }

    public function isCalibrated(): bool
    {
        return $this->metres_per_unit !== null;
    }

    /** The name shown in lists: the sheet number if given, otherwise the page. */
    public function label(): string
    {
        return $this->sheet_number ?: __('Page :page', ['page' => $this->page_no]);
    }
}
