<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One call for a page that cost something, charged something or came back unusable, dated by when
 * that call ended. The page row adds up all of its calls for the read's own totals. The limits of a
 * day or a month count these rows instead, so a page read again today brings neither yesterday's
 * cost nor yesterday's charge nor yesterday's unusable answer into today.
 *
 * Written only by PageReader, in the transaction that settles the call. Never changed afterwards.
 */
class DocumentReadAttempt extends Model
{
    use BelongsToOrganization;

    /** It is seen by whoever may see its read. */
    public const PROJECT_VIA = 'documentRead';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $page = DocumentReadPage::withoutGlobalScopes()->find($attempt->document_read_page_id)
                ?? throw new LogicException('An attempt belongs to a page that exists.');

            $attempt->organization_id = $page->organization_id;
            $attempt->document_read_id = $page->document_read_id;
        });
    }

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'credits' => 'decimal:4',
            'absorbed_usd' => 'decimal:6',
            'behaviour_failure' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }

    public function documentRead(): BelongsTo
    {
        return $this->belongsTo(DocumentRead::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(DocumentReadPage::class, 'document_read_page_id');
    }
}
