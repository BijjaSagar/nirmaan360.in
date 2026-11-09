<?php

namespace App\Services\Crm;

use Illuminate\Database\Eloquent\Builder;

/** Small query helpers shared by the CRM lists. */
final class Search
{
    /**
     * Case-insensitive "contains" across the given columns, on SQLite and PostgreSQL alike.
     *
     * @param  list<string>  $columns  Fixed column names from our own code, never user input.
     */
    public static function contains(Builder $query, array $columns, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        return $query->where(function (Builder $inner) use ($columns, $like) {
            foreach ($columns as $column) {
                $inner->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$like]);
            }
        });
    }

    /** Only rows carrying the tag. The tag is parsed first so it matches how tags are stored. */
    public static function tagged(Builder $query, ?string $tag, string $column = 'tags'): Builder
    {
        $tag = TagService::parse($tag)[0] ?? null;

        return $tag === null ? $query : $query->whereJsonContains($column, $tag);
    }
}
