<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentRead;
use App\Models\User;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\Notify\Audience;

/** Who may work with a read. Usable on the queue, where nobody is signed in. */
final class ReadAccess
{
    /**
     * Whether this person belongs to the read's company, sees its project and holds what the
     * read's kind asks for: every permission under "all" and one of each list under "any".
     */
    public static function holds(User $user, DocumentRead $read, ReadKind $kind): bool
    {
        if ((int) $user->organization_id !== (int) $read->organization_id || ! Audience::seesProject($user, $read->project_id)) {
            return false;
        }

        $abilities = $kind->abilities($read);

        if (! Audience::holds($user, $abilities['all'] ?? [])) {
            return false;
        }

        foreach ($abilities['any'] ?? [] as $group) {
            if (array_filter($group, $user->hasPermission(...)) === []) {
                return false;
            }
        }

        return true;
    }
}
