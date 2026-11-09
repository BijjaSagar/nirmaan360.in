<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bbs_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 32);
            $table->string('title', 160);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['project_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('bbs_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bbs_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('location', 120)->nullable();
            $table->string('mark', 32);
            $table->string('steel_type', 16);
            $table->unsignedSmallInteger('dia_mm');
            $table->unsignedInteger('sets');
            $table->unsignedInteger('per_set');
            $table->unsignedInteger('total_bars');
            $table->string('shape_code', 8);
            $table->json('dims');
            $table->decimal('hook_allowance_mm', 14, 3);
            $table->decimal('bend_allowance_mm', 14, 3);
            $table->decimal('raw_length_mm', 14, 3);
            $table->decimal('cutting_length_mm', 14, 3);
            $table->decimal('total_length_m', 14, 3);
            $table->decimal('unit_weight_kg_m', 14, 3);
            $table->decimal('total_weight_kg', 14, 3);
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->index(['bbs_schedule_id', 'sort']);
            $table->unique(['organization_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bbs_bars');
        Schema::dropIfExists('bbs_schedules');
    }
};
