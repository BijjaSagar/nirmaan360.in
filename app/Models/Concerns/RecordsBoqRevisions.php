<?php

namespace App\Models\Concerns;

use App\Services\ProjectSetup\BoqRevisions;

/** Writes a boq_revisions row for every add, edit and delete of a BOQ line. */
trait RecordsBoqRevisions
{
    public static function bootRecordsBoqRevisions(): void
    {
        static::created(fn ($item) => BoqRevisions::record($item, 'added'));
        static::updated(fn ($item) => BoqRevisions::record($item, 'edited'));
        static::deleted(fn ($item) => BoqRevisions::record($item, 'deleted'));
        static::restored(fn ($item) => BoqRevisions::record($item, 'restored'));
    }
}
