<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrawingVersion extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(Drawing::class);
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(DrawingSheet::class)->orderBy('page_no');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function transmittalItems(): HasMany
    {
        return $this->hasMany(DrawingTransmittalItem::class);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    /** Whether a browser can show this file on a sheet canvas. */
    public function isViewable(): bool
    {
        return $this->isPdf() || $this->isImage();
    }
}
