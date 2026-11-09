<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_library_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('item');
            $table->string('unit', 16);
            $table->decimal('rate', 15, 2)->default(0);
            $table->string('category', 80)->nullable();
            // Optional rate analysis. When any part is filled the rate is their total.
            $table->decimal('material_rate', 15, 2)->nullable();
            $table->decimal('labour_rate', 15, 2)->nullable();
            $table->decimal('plant_rate', 15, 2)->nullable();
            $table->decimal('overhead_rate', 15, 2)->nullable();
            $table->decimal('profit_rate', 15, 2)->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'category']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('report', 40);
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('period', 20)->default('last_30_days');
            $table->string('format', 8)->default('xlsx');
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('hour')->default(7);
            $table->json('recipients');
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 255)->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['active', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('rate_library_items');
    }
};
