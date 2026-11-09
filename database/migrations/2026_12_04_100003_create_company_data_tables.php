<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        // No foreign key on purpose: this row outlives the company as the record that it was deleted.
        Schema::create('company_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('purge_after');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'purge_after']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_deletion_requests');
        Schema::dropIfExists('company_data_exports');
    }
};
