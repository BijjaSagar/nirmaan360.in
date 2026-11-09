<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('path');
            $table->string('caption')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['issue_id', 'kind']);
        });

        Schema::create('measurement_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('measurement_entry_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('path');
            $table->string('caption')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index('measurement_entry_id');
        });

        Schema::create('bill_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ra_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('kind', 12);
            $table->string('number', 60);
            $table->date('note_date');
            $table->text('reason');
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('gst_pct', 5, 2)->default(0);
            $table->decimal('gst_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            // Signed change to the bill's net payable, fixed when the note is raised.
            $table->decimal('net_effect', 15, 2);
            $table->string('status')->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['ra_bill_id', 'status']);
            $table->index(['project_id', 'status']);
        });

        Schema::table('ra_bills', function (Blueprint $table) {
            $table->boolean('is_final')->default(false);
            $table->decimal('retention_released', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->nullable();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::table('ra_bills', function (Blueprint $table) {
            $table->dropColumn(['is_final', 'retention_released', 'paid_amount']);
        });

        Schema::dropIfExists('bill_notes');
        Schema::dropIfExists('measurement_attachments');
        Schema::dropIfExists('issue_photos');
    }
};
