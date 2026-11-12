<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sign-in, sign-up and invitations find an account by lower(email), which the unique index on email cannot
 * serve. Not unique: rows from before emails were stored in lower case may differ only by case, and
 * User::findByEmail handles those.
 *
 * Built CONCURRENTLY on PostgreSQL so sign-ins go on during the build. That cannot happen inside a
 * transaction, so this migration runs outside one.
 *
 * SQLite rebuilds a table for some changes, such as dropping a column with a foreign key or ->change(), and
 * Laravel cannot carry an expression index across that rebuild. A later migration that does so to users must
 * drop this index first and create it again after.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'users_email_lower_index';

    public function up(): void
    {
        // The expression must stay exactly lower(email), as written in User's lookups, or the index is not used.
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS '.self::INDEX.' ON users (lower(email))'),
            'sqlite' => DB::statement('CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON users (lower(email))'),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS '.self::INDEX),
            'sqlite' => DB::statement('DROP INDEX IF EXISTS '.self::INDEX),
            default => null,
        };
    }

    /** A plain build when a transaction is already open, as in a test that rolls this back and forth. */
    private function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
