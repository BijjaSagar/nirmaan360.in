<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 60)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('category', 60)->nullable();
            $table->foreignId('base_unit_id')->constrained('material_units');
            $table->string('hsn_code', 12)->nullable();
            $table->decimal('gst_pct', 5, 2)->default(18);
            $table->boolean('is_active')->default(true);
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        // factor = how many base units make one of this unit, e.g. 1 bag = 50 kg.
        Schema::create('material_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('material_units');
            $table->decimal('factor', 18, 6);
            $table->timestamps();
            $table->unique(['material_id', 'unit_id']);
        });

        Schema::create('material_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'name']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        // One row per store and material. Holds no quantity: stock is always summed from
        // stock_ledger. Writers lock this row, so it is what serialises movements.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('material_stores')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->decimal('reorder_level', 14, 3)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'material_id']);
        });

        // Material one unit of BOQ work should use, in the material's base unit.
        Schema::create('boq_material_norms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_per_unit', 14, 6);
            $table->decimal('wastage_pct', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['boq_item_id', 'material_id']);
            $table->index(['project_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_material_norms');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('material_stores');
        Schema::dropIfExists('material_unit_conversions');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('material_units');
    }
};
