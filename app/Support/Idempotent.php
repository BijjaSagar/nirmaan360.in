<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class Idempotent
{
    /**
     * Insert a row guarded by a unique key. If a concurrent retry got there first, return
     * the row it wrote. The nested transaction matters on PostgreSQL: a failed insert would
     * otherwise abort any outer transaction and the follow-up read would fail too.
     *
     * @template TModel of Model
     *
     * @param  callable(): TModel  $create
     * @param  callable(): ?TModel  $find
     * @return TModel
     */
    public static function create(callable $create, callable $find): Model
    {
        try {
            return DB::transaction($create);
        } catch (UniqueConstraintViolationException $exception) {
            return $find() ?? throw $exception;
        }
    }
}
