<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('takeoff_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('colour', 7);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::create('takeoff_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('takeoff_layer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 8);
            $table->string('label', 160)->nullable();
            $table->json('points');
            $table->decimal('depth', 14, 3)->nullable();
            $table->decimal('qty', 14, 3);
            $table->string('unit', 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'boq_item_id']);
            $table->index('drawing_sheet_id');
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('drawing_issue_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->decimal('x', 12, 3);
            $table->decimal('y', 12, 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique('issue_id');
            $table->index('drawing_sheet_id');
            $table->unique(['organization_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_issue_pins');
        Schema::dropIfExists('takeoff_measurements');
        Schema::dropIfExists('takeoff_layers');
    }
};
