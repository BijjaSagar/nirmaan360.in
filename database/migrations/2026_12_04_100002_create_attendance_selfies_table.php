<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_selfies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('photo_path');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy_m', 8, 1)->nullable();
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('radius_m');
            $table->timestamp('captured_at');
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            // The exact note shown when the photo was taken, kept as proof of consent.
            $table->string('consent_version', 16);
            $table->text('consent_text');
            $table->timestamp('consent_at');
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->unique(['project_id', 'worker_id', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_selfies');
    }
};
