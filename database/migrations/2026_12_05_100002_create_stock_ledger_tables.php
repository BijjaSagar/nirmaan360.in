<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. Foreign keys deliberately have no cascade, so a delete elsewhere
        // cannot remove or rewrite a movement.
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('store_id')->constrained('material_stores');
            $table->foreignId('material_id')->constrained();
            $table->string('movement_type', 20);
            $table->decimal('qty', 14, 3);
            $table->date('movement_date');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('reverses_id')->nullable();
            $table->unsignedBigInteger('boq_item_id')->nullable();
            $table->string('activity', 160)->nullable();
            // Unique per organisation, so one document line can never post twice.
            $table->string('posting_key', 80);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['organization_id', 'posting_key']);
            $table->index(['store_id', 'material_id', 'id']);
            $table->index(['project_id', 'store_id', 'material_id']);
            $table->index(['boq_item_id', 'material_id']);
            $table->index(['source_type', 'source_id']);
        });

        match (DB::getDriverName()) {
            'pgsql' => $this->createPostgresTriggers(),
            'sqlite' => $this->createSqliteTriggers(),
            default => null,
        };

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('material_stores');
            $table->foreignId('material_id')->constrained();
            $table->string('number', 40);
            $table->string('adjustment_type', 20);
            $table->decimal('qty', 14, 3);
            $table->foreignId('unit_id')->constrained('material_units');
            $table->decimal('base_qty', 14, 3);
            $table->date('adjustment_date');
            $table->string('reason', 255);
            $table->string('status', 20)->default('POSTED');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('procurement_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('series', 10);
            $table->string('financial_year', 7);
            $table->unsignedInteger('last_no')->default(0);
            $table->unique(['organization_id', 'series', 'financial_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_counters');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_ledger');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS stock_ledger_append_only()');
        }
    }

    private function createPostgresTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_ledger_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_ledger is append-only: post a reversal instead';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER stock_ledger_no_change BEFORE UPDATE OR DELETE ON stock_ledger
            FOR EACH ROW EXECUTE FUNCTION stock_ledger_append_only()
        SQL);
    }

    private function createSqliteTriggers(): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'stock_ledger_no_'.strtolower($operation);

            DB::statement(<<<SQL
                CREATE TRIGGER {$name} BEFORE {$operation} ON stock_ledger
                BEGIN
                    SELECT RAISE(ABORT, 'stock_ledger is append-only: post a reversal instead');
                END
            SQL);
        }
    }
};
