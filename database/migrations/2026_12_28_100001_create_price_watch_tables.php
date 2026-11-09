<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The materials a company watches the price of: cement, steel, sand and so on.
        Schema::create('price_watch_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Set on a material from the default list, so the shared list can feed it. Null for a company's own material.
            $table->string('key', 40)->nullable();
            $table->string('name', 160);
            // The unit the price is quoted per: bag, kg, brass, nos, litre.
            $table->string('unit', 20);
            // The procurement catalogue row this stands for, so BOQ norms and deliveries can be found. Another module's table, so no foreign key.
            $table->unsignedBigInteger('material_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'is_active', 'sort']);
        });

        // The dated price list. Append-only in use: the newest row by date is the current price, and every price keeps its date and source.
        Schema::create('material_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_watch_material_id')->constrained('price_watch_materials')->cascadeOnDelete();
            $table->decimal('rate', 15, 2);
            $table->string('unit', 20);
            $table->date('priced_on');
            $table->string('source', 160)->nullable();
            // manual, excel, shared, or provider:<key> for a data provider plugged in later.
            $table->string('source_kind', 40)->default('manual');
            $table->foreignId('noted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['price_watch_material_id', 'priced_on', 'id']);
        });

        // Per project: the rate the BOQ was priced at for a watched material, and the quantity the BOQ needs when no material norm gives it.
        Schema::create('price_watch_project_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_watch_material_id')->constrained('price_watch_materials')->cascadeOnDelete();
            $table->decimal('boq_rate', 15, 2)->nullable();
            $table->decimal('boq_qty', 14, 3)->nullable();
            $table->foreignId('noted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'price_watch_material_id']);
        });

        // The list the platform admin keeps in /admin, which any company can adopt. Not a tenant table.
        Schema::create('price_watch_shared_prices', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 160);
            $table->string('unit', 20);
            $table->decimal('rate', 15, 2);
            $table->date('priced_on');
            $table->string('source', 160)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_watch_shared_prices');
        Schema::dropIfExists('price_watch_project_rates');
        Schema::dropIfExists('material_prices');
        Schema::dropIfExists('price_watch_materials');
    }
};
