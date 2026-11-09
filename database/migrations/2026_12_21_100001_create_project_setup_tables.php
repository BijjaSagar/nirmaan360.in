<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Approvals needed before building starts: building plan sanction, fire NOC and so on.
        Schema::create('project_permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('authority', 160)->nullable();
            $table->text('documents_required')->nullable();
            // Money, so it is shown only to people who may see the budget.
            $table->decimal('fee', 15, 2)->nullable();
            $table->date('applied_on')->nullable();
            $table->date('approved_on')->nullable();
            $table->string('status', 20)->default('not_started');
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('project_permit_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_permit_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 255);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Append-only. One row for every add, edit or delete of a BOQ line, with the
        // value of the whole BOQ just before and just after it.
        Schema::create('boq_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // No foreign key: a removed line keeps its history.
            $table->unsignedBigInteger('boq_item_id')->nullable()->index();
            $table->uuid('batch_uuid')->nullable()->index();
            $table->string('action', 12);
            $table->string('source', 24)->nullable();
            $table->string('item_code', 32);
            $table->string('item_description', 500)->nullable();
            // {"qty": ["100.000", "120.000"], ...}, old value then new value.
            $table->json('changes');
            $table->text('reason')->nullable();
            $table->decimal('valuation_before', 15, 2)->default(0);
            $table->decimal('valuation_after', 15, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_id', 'id']);
        });

        // Material rates typed in by hand. There is no price feed behind them.
        Schema::create('boq_current_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 15, 2);
            $table->date('noted_on');
            $table->string('source', 160)->nullable();
            $table->foreignId('noted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['boq_item_id', 'noted_on', 'id']);
        });

        Schema::table('boq_items', function (Blueprint $table) {
            $table->string('category', 40)->nullable();
            $table->text('details')->nullable();
            $table->index(['project_id', 'category']);
        });

        Schema::table('boq_material_norms', function (Blueprint $table) {
            // Set on the cement, aggregate and sand rows of a concrete line mixed on site.
            $table->string('mix_role', 24)->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            // detailed: line by line. thumb_rule: a few overall lines.
            $table->string('boq_mode', 12)->default('detailed');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('boq_mode');
        });

        Schema::table('boq_material_norms', function (Blueprint $table) {
            $table->dropColumn('mix_role');
        });

        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'category']);
            $table->dropColumn(['category', 'details']);
        });

        Schema::dropIfExists('boq_current_rates');
        Schema::dropIfExists('boq_revisions');
        Schema::dropIfExists('project_permit_files');
        Schema::dropIfExists('project_permits');
    }
};
