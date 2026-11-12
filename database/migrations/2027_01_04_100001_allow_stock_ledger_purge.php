<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * stock_ledger stays append-only, with one exception: erasing a whole company. The purge writes a
 * marker row for that company, deletes its movements and removes the marker, all in one
 * transaction. Every UPDATE, and every DELETE without the marker, is still refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Not called organization_id, so the company export and the purge sweep never treat it as company data.
        Schema::create('stock_ledger_purges', function (Blueprint $table) {
            $table->unsignedBigInteger('purging_organization_id')->primary();
            $table->timestamp('created_at')->nullable();
        });

        match (DB::getDriverName()) {
            'pgsql' => $this->allowPostgresPurge(),
            'sqlite' => $this->allowSqlitePurge(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->restorePostgresTriggers(),
            'sqlite' => $this->restoreSqliteTriggers(),
            default => null,
        };

        Schema::dropIfExists('stock_ledger_purges');
    }

    private function allowPostgresPurge(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_ledger_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND EXISTS (SELECT 1 FROM stock_ledger_purges WHERE purging_organization_id = OLD.organization_id) THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'stock_ledger is append-only: post a reversal instead';
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    private function allowSqlitePurge(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS stock_ledger_no_delete');

        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_ledger_no_delete BEFORE DELETE ON stock_ledger
            WHEN NOT EXISTS (SELECT 1 FROM stock_ledger_purges WHERE purging_organization_id = OLD.organization_id)
            BEGIN
                SELECT RAISE(ABORT, 'stock_ledger is append-only: post a reversal instead');
            END
        SQL);
    }

    /** Word for word what 2026_12_05_100002_create_stock_ledger_tables made, so a rollback leaves no trace. */
    private function restorePostgresTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_ledger_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_ledger is append-only: post a reversal instead';
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    private function restoreSqliteTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS stock_ledger_no_delete');

        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_ledger_no_delete BEFORE DELETE ON stock_ledger
            BEGIN
                SELECT RAISE(ABORT, 'stock_ledger is append-only: post a reversal instead');
            END
        SQL);
    }
};
