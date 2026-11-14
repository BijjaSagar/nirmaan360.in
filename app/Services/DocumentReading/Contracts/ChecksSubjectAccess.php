<?php

namespace App\Services\DocumentReading\Contracts;

use App\Models\DocumentRead;
use App\Models\User;

/**
 * A kind of read whose project limit is not the read's own project_id: what the read is of can move
 * into a project after the read was opened, as a tender does when it is won. ReadAccess asks this
 * beside the read's own company and project, on every shared route and on the queue.
 */
interface ChecksSubjectAccess
{
    /**
     * Whether this person can still see what the read is of. Runs with nobody signed in, so it names
     * the read's company and checks the person's project limit itself.
     */
    public function sees(User $user, DocumentRead $read): bool;
}
