<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;

class AgentCreditTopup extends Model
{
    use BelongsToOrganization;

    protected $table = 'credit_topups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credits' => 'decimal:4',
            'base_amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** The amount in paise, as the payment gateway expects it. */
    public function paise(): string
    {
        return (string) Dec::of($this->total_amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp);
    }
}
