<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One run of the pre-check on an RA bill: what it found, kept as it was found. A later run is a new row.
        Schema::create('bill_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ra_bill_id')->constrained('ra_bills')->cascadeOnDelete();
            $table->json('flags');
            $table->char('flags_hash', 64);
            $table->json('summary');
            $table->json('expected')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['ra_bill_id', 'id']);
        });

        // A person saying, with a remark, that they have seen one finding of one run.
        Schema::create('bill_check_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_check_id')->constrained('bill_checks')->cascadeOnDelete();
            $table->string('flag_key', 80);
            $table->string('remark', 500);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['bill_check_id', 'flag_key']);
        });

        // Whether a company wants every finding acknowledged before a bill is submitted. No row means it does not.
        Schema::create('bill_check_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('required')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_check_policies');
        Schema::dropIfExists('bill_check_acks');
        Schema::dropIfExists('bill_checks');
    }
};
