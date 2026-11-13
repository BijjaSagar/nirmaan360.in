<?php

namespace App\Services\DocumentReading\Contracts;

/**
 * Something that is kept for a time and then deleted or blanked, such as the text of a voice note.
 * A module lists the class under "retention" in its own config/modules file; the command that
 * tends document reads runs every listed class once a minute.
 *
 * It runs with no signed-in person, across every company, so it must be quick when nothing is due.
 */
interface Retains
{
    /** Deletes or blanks whatever is past its time. Returns how many records it changed. */
    public function purgeOld(): int;
}
