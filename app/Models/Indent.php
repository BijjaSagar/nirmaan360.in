<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Indent extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['required_by' => 'date'];
    }

    public function approvalDocType(): string
    {
        return 'indent';
    }

    public function approvalTitle(): string
    {
        return 'Indent '.$this->number;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'store_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IndentItem::class)->orderBy('id');
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class)->latest('id');
    }
}
