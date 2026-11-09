<?php

namespace App\Services\Notify;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds a company's data by reading the schema, so a table added by any other
 * module is exported and deleted without this class having to know about it.
 * Runs on the queue and in the console, where no tenant scope applies.
 */
class TenantData
{
    /** Credentials never leave the server, in any module's table. */
    private const CREDENTIAL_COLUMN = '/(^|_)(password|secret|token|api_key|apikey|private_key|code_hash)(_|$)/i';

    /** @return array<string, list<string>> Every table with an organization_id, and its columns. */
    public function tables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $columns = Schema::getColumnListing($table);

            if (in_array('organization_id', $columns, true)) {
                $tables[$table] = $columns;
            }
        }

        ksort($tables);

        return $tables;
    }

    /** @param list<string> $columns */
    public function exportableColumns(array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => ! preg_match(self::CREDENTIAL_COLUMN, $column)));
    }

    /** @return iterable<object> */
    public function rows(string $table, array $columns, int $organizationId): iterable
    {
        $query = DB::table($table)->where('organization_id', $organizationId);

        return in_array('id', $columns, true) ? $query->lazyById(500) : $query->cursor();
    }

    /** @return Collection<int, int> */
    public function userIds(int $organizationId): Collection
    {
        return DB::table('users')->where('organization_id', $organizationId)->pluck('id');
    }

    /** Stored files follow the naming used across the app: a column called path, or ending in _path. */
    public function fileColumns(array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => $column === 'path' || str_ends_with($column, '_path')));
    }

    /** Stored paths are written by the server, but the check costs nothing before a read or a delete. */
    public function isSafePath(mixed $path): bool
    {
        return is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/');
    }

    /** @return list<string> Every stored file the company owns, including earlier exports. */
    public function storedFiles(int $organizationId): array
    {
        $paths = [];

        foreach ($this->tables() as $table => $columns) {
            foreach ($this->fileColumns($columns) as $column) {
                foreach (DB::table($table)->where('organization_id', $organizationId)->whereNotNull($column)->pluck($column) as $path) {
                    if ($this->isSafePath($path)) {
                        $paths[] = $path;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public function notificationsQuery(int $organizationId)
    {
        return DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $this->userIds($organizationId));
    }
}
