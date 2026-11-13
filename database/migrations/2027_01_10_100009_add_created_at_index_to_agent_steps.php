<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The readers' monthly limit adds up what every company's agent steps cost since the start of the
 * month. The only date index agent_steps had starts with organization_id, so that sum read the whole
 * table; with this one it reads only the month's rows.
 *
 * Built CONCURRENTLY on PostgreSQL so the agents go on writing steps during the build. That cannot
 * happen inside a transaction, so this migration runs outside one.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'agent_steps_created_at_index';

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS '.self::INDEX.' ON agent_steps (created_at)');

            return;
        }

        Schema::table('agent_steps', function (Blueprint $table) {
            $table->index('created_at', self::INDEX);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS '.self::INDEX);

            return;
        }

        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }

    /** A plain build when a transaction is already open, as in a test that rolls this back and forth. */
    private function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
