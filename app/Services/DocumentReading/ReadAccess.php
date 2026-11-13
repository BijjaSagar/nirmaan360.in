<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentRead;
use App\Models\Organization;
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

    /**
     * For a request: stops unless this person may open the read, and returns the read's kind.
     *
     * 404 when no reader for this kind is deployed, so a read of a module that is not there cannot
     * be told from one that does not exist. 403 when the person lacks what the kind asks for, when
     * the company's plan does not include the module the kind belongs to, or when the read is one
     * person's own (a slip, a bill) and this is neither that person nor an owner or admin.
     *
     * The company and the project limit were already applied when the read was found for the route.
     */
    public static function assert(DocumentRead $read, User $user): ReadKind
    {
        $kind = app(ReadKinds::class)->find($read->kind);

        abort_if($kind === null, 404);
        abort_unless(self::holds($user, $read, $kind) && self::mayOpen($user, $read), 403);

        $feature = config("modules.document_reading.kinds.{$read->kind}.feature");

        abort_unless(
            ! is_string($feature) || (bool) Organization::find($read->organization_id)?->allowsFeature($feature),
            403,
            __('Your plan does not include this module. Upgrade to continue.')
        );

        return $kind;
    }

    /**
     * A kind marked "own_only" keeps a read to the person it belongs to, and to owners and admins.
     * A read with nobody on it any more is theirs alone.
     */
    public static function mayOpen(User $user, DocumentRead $read): bool
    {
        if (! config("modules.document_reading.kinds.{$read->kind}.own_only", false)) {
            return true;
        }

        return ($read->user_id !== null && (int) $read->user_id === (int) $user->id) || self::leads($user);
    }

    /** An owner or admin by the built-in role. A role a company made itself is never one of them. */
    public static function leads(User $user): bool
    {
        return $user->custom_role_id === null && in_array($user->role, User::SEES_ALL_PROJECTS, true);
    }
}
