<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The building dimensions a project's BOQ is worked out from. One row per project, edited and run
 * again as the design settles. Element groups are lists of rows whose figures are decimal strings.
 */
class AutoBoqInput extends Model
{
    use Auditable, BelongsToOrganization;

    /** The lists of element rows and the scalar figures, as stored. */
    public const GROUPS = ['footings', 'columns', 'beams', 'slabs', 'walls'];

    public const SCALARS = ['floor_height', 'plinth_area', 'plinth_height', 'foundation_depth', 'stair_area', 'chajja_length', 'chajja_width', 'chajja_thickness'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'floors' => 'integer',
            'floor_height' => 'decimal:3',
            'plinth_area' => 'decimal:3',
            'plinth_height' => 'decimal:3',
            'foundation_depth' => 'decimal:3',
            'stair_area' => 'decimal:3',
            'chajja_length' => 'decimal:3',
            'chajja_width' => 'decimal:3',
            'chajja_thickness' => 'decimal:3',
            'footings' => 'array',
            'columns' => 'array',
            'beams' => 'array',
            'slabs' => 'array',
            'walls' => 'array',
            'layers' => 'array',
            'excluded' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The row as the calculator reads it: scalars and element lists, with blanks as empty lists. */
    public function dimensions(): array
    {
        $dimensions = ['floors' => (int) $this->floors];

        foreach (self::SCALARS as $field) {
            $dimensions[$field] = (string) $this->{$field};
        }

        foreach (self::GROUPS as $group) {
            $dimensions[$group] = array_values($this->{$group} ?? []);
        }

        return $dimensions;
    }
}
