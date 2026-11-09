<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A company-defined extra field on leads, companies or tenders. Values live in each record's custom_fields. */
class CrmCustomField extends Model
{
    use BelongsToOrganization;

    public const COMPANY_WIDE = true;

    public const ENTITIES = ['lead', 'company', 'tender'];

    public const TYPES = ['text', 'number', 'date', 'select'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'active' => 'boolean'];
    }
}
