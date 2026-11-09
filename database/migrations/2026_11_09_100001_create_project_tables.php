<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('pan', 12)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'type']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('client_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('location')->nullable();
            $table->string('state')->nullable();
            $table->decimal('contract_value', 15, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->decimal('retention_pct', 5, 2)->default(5);
            $table->decimal('tds_pct', 5, 2)->default(2);
            $table->decimal('gst_pct', 5, 2)->default(18);
            $table->decimal('advance_amount', 15, 2)->default(0);
            $table->decimal('advance_recovery_pct', 5, 2)->default(0);
            $table->decimal('measurement_tolerance_pct', 5, 2)->default(5);
            $table->json('deduction_order')->nullable();
            $table->string('deduction_basis')->default('gross');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_m')->default(200);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('boq_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('boq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32);
            $table->text('description');
            $table->string('unit', 16);
            $table->decimal('qty', 14, 3)->default(0);
            $table->decimal('rate', 15, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_items');
        Schema::dropIfExists('boq_sections');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('parties');
    }
};
