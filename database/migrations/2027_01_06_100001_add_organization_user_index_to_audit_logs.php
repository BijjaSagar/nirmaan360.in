<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account rewrites that person's own audit rows while the company row is locked. Without this
 * index each batch read the whole company's audit history; with it, it reads only theirs, in id order.
 *
 * Built CONCURRENTLY on PostgreSQL so writes to audit_logs go on during the build. That cannot happen inside
 * a transaction, so this migration runs outside one.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'audit_logs_organization_id_user_id_id_index';

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS '.self::INDEX.' ON audit_logs (organization_id, user_id, id)');

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['organization_id', 'user_id', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS '.self::INDEX);

            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    /** A plain build when a transaction is already open, as in a test that rolls this back and forth. */
    private function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
