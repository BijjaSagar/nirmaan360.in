<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A material whose price a company watches. Its prices are the dated rows in material_prices.
 * A material from the default list carries a key, which is how the shared list finds it.
 * Materials are switched off, never deleted, so the default list is only ever added once.
 */
class PriceWatchMaterial extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(MaterialPrice::class)->orderByDesc('priced_on')->orderByDesc('id');
    }

    /** The procurement catalogue row this stands for, when linked. */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function projectRates(): HasMany
    {
        return $this->hasMany(PriceWatchProjectRate::class);
    }

    /** A default material is named in the reader's language; a company's own keeps the name it was given. */
    public function label(): string
    {
        return $this->key !== null ? __($this->name) : $this->name;
    }
}
