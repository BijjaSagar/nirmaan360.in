<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for App\Models\AiTextCall while the part of the module that brings the real model and
 * its table has not been merged. Once the real class exists this one is no longer used.
 */
class TextCallStandIn extends Model
{
    use BelongsToOrganization;

    protected $table = 'ai_text_calls';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['max_credits' => 'decimal:4', 'credits' => 'decimal:4', 'cost_usd' => 'decimal:6'];
    }
}
