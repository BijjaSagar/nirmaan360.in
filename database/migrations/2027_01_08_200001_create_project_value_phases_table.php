<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project's contract value split into phases: foundation, slab, finishing and so on.
        // When a project has phases, its contract_value is their sum, so everything that reads it still works.
        Schema::create('project_value_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['project_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_value_phases');
    }
};
