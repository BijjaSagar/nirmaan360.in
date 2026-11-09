<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawing_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('drawing_folders')->nullOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            $table->index(['project_id', 'parent_id']);
        });

        Schema::create('drawings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('drawing');
            $table->string('number', 64);
            $table->string('title', 200);
            $table->string('discipline', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['project_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('drawing_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('revision', 16);
            $table->string('status', 24)->default('for_information');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('note', 500)->nullable();
            $table->boolean('is_current')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['drawing_id', 'version_no']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'is_current']);
        });

        Schema::create('drawing_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_no');
            $table->string('sheet_number', 64)->nullable();
            $table->string('title', 200)->nullable();
            $table->decimal('width', 12, 3);
            $table->decimal('height', 12, 3);
            $table->json('calib_points')->nullable();
            $table->decimal('calib_length_m', 14, 3)->nullable();
            $table->decimal('metres_per_unit', 20, 12)->nullable();
            $table->foreignId('calibrated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calibrated_at')->nullable();
            $table->timestamps();
            $table->unique(['drawing_version_id', 'page_no']);
        });

        Schema::create('drawing_transmittals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 32);
            $table->string('recipient_name', 160);
            $table->string('recipient_company', 160)->nullable();
            $table->string('subject', 200);
            $table->string('purpose', 32);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('issued');
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('acknowledged_on')->nullable();
            $table->string('acknowledged_by', 160)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('drawing_transmittal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_transmittal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drawing_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->timestamps();
            $table->unique(['drawing_transmittal_id', 'drawing_version_id'], 'drawing_transmittal_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_transmittal_items');
        Schema::dropIfExists('drawing_transmittals');
        Schema::dropIfExists('drawing_sheets');
        Schema::dropIfExists('drawing_versions');
        Schema::dropIfExists('drawings');
        Schema::dropIfExists('drawing_folders');
    }
};
