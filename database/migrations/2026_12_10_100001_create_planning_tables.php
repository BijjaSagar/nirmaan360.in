<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_wbs_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('plan_wbs_nodes')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->string('external_uid', 40)->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->unique(['project_id', 'external_uid']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('plan_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wbs_node_id')->nullable()->constrained('plan_wbs_nodes')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->date('planned_start');
            $table->date('planned_end');
            $table->date('baseline_start')->nullable();
            $table->date('baseline_end')->nullable();
            $table->boolean('is_milestone')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('external_uid', 40)->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->unique(['project_id', 'external_uid']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'planned_start']);
        });

        Schema::create('plan_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('predecessor_id')->constrained('plan_activities')->cascadeOnDelete();
            $table->foreignId('successor_id')->constrained('plan_activities')->cascadeOnDelete();
            $table->string('type', 2)->default('FS');
            $table->integer('lag_days')->default(0);
            $table->timestamps();
            $table->unique(['predecessor_id', 'successor_id']);
            $table->index('successor_id');
        });

        Schema::create('plan_boq_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_activity_id')->constrained('plan_activities')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            $table->decimal('qty', 14, 3);
            $table->timestamps();
            $table->unique(['plan_activity_id', 'boq_item_id']);
            $table->index('boq_item_id');
        });

        Schema::create('plan_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('head', 40);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('baseline_amount', 15, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'head']);
        });

        Schema::create('plan_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('head', 40);
            $table->date('entry_date');
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'head']);
        });

        Schema::create('plan_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at');
            $table->unsignedInteger('activity_count')->default(0);
            $table->decimal('budget_total', 15, 2)->default(0);
            $table->timestamps();
            $table->index(['project_id', 'taken_at']);
        });

        Schema::create('plan_delay_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_activity_id')->constrained('plan_activities')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->integer('days_late')->default(0);
            $table->date('first_seen_on');
            $table->timestamp('notified_at')->nullable();
            $table->date('resolved_on')->nullable();
            $table->timestamps();
            $table->unique(['plan_activity_id', 'kind']);
            $table->index(['project_id', 'resolved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_delay_alerts');
        Schema::dropIfExists('plan_baselines');
        Schema::dropIfExists('plan_cost_entries');
        Schema::dropIfExists('plan_budget_lines');
        Schema::dropIfExists('plan_boq_links');
        Schema::dropIfExists('plan_dependencies');
        Schema::dropIfExists('plan_activities');
        Schema::dropIfExists('plan_wbs_nodes');
    }
};
