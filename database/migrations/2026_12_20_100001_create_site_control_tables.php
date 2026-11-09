<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::create('progress_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_floor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            // Negative corrects an earlier entry, so history is never rewritten.
            $table->decimal('qty_done', 14, 3);
            $table->text('remark')->nullable();
            $table->boolean('is_pending')->default(false);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'boq_item_id']);
            $table->index(['project_id', 'project_floor_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('planned_days')->nullable();
            $table->date('deadline_date')->nullable();
            $table->decimal('budget_amount', 15, 2)->nullable();
            $table->decimal('budget_margin_pct', 5, 2)->default(5);
            $table->decimal('payment_gap_alert_pct', 5, 2)->default(5);
            $table->string('plot_size', 60)->nullable();
            $table->string('built_up_area', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['planned_days', 'deadline_date', 'budget_amount', 'budget_margin_pct', 'payment_gap_alert_pct', 'plot_size', 'built_up_area']);
        });

        Schema::dropIfExists('progress_entries');
        Schema::dropIfExists('project_floors');
    }
};
