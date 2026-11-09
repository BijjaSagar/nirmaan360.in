<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A message a person can open in WhatsApp or their mail app. Nothing is sent from the server. */
class CrmTemplate extends Model
{
    use BelongsToOrganization;

    public const CHANNELS = ['whatsapp', 'email'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
