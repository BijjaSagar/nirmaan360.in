<?php

namespace App\Support;

use App\Models\Concerns\ProjectAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A repeated client_uuid is looked up across the whole company, because trashed rows
 * still hold their key, so that lookup skips the project limit. Every result passes
 * through here. A row the person cannot reach is an error rather than null, because
 * null would carry on to an insert and break the unique key.
 */
final class ClientUuidRepeat
{
    /**
     * @template TModel of Model
     *
     * @param  TModel|null  $row
     * @return TModel|null
     *
     * @throws ValidationException
     */
    public static function reachable(?Model $row): ?Model
    {
        if ($row === null || self::canReach($row)) {
            return $row;
        }

        throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
    }

    /** Only the project limit is applied, so a trashed row in a reachable project still counts. */
    private static function canReach(Model $row): bool
    {
        $query = $row->newQueryWithoutScopes()->whereKey($row->getKey());
        (new ProjectAccessScope)->apply($query, $row);

        return $query->exists();
    }
}
