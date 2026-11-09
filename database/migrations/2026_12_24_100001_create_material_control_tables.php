<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Material, store and unit ids point at the procurement module, so they stay plain ids.
        Schema::create('delivery_challans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('number', 40);
            $table->string('challan_no', 60)->nullable();
            $table->date('challan_date');
            $table->string('vehicle_no', 30)->nullable();
            $table->decimal('qty', 14, 3);
            $table->decimal('base_qty', 14, 3);
            // What the paper challan says, when that differs from what was counted on site.
            $table->decimal('challan_qty', 14, 3)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('bill_status', 12)->default('pending');
            $table->string('bill_path')->nullable();
            $table->string('bill_no', 60)->nullable();
            $table->date('bill_date')->nullable();
            $table->decimal('bill_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('vendor_bill_id')->nullable();
            $table->foreignId('bill_attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('bill_attached_at')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('POSTED');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'bill_status']);
            $table->index(['project_id', 'challan_date']);
            $table->index(['party_id', 'status']);
        });

        Schema::create('material_short_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            $table->foreignId('delivery_challan_id')->constrained('delivery_challans')->cascadeOnDelete();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('short_qty', 14, 3);
            $table->decimal('short_base_qty', 14, 3);
            $table->date('found_on');
            // True when found after stock was already posted, so the correction went to the ledger.
            $table->boolean('ledger_posted')->default(false);
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('POSTED');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['party_id', 'status']);
            $table->index(['project_id', 'material_id']);
        });

        Schema::create('material_stock_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->date('check_date');
            $table->decimal('physical_qty', 14, 3);
            $table->decimal('physical_base_qty', 14, 3);
            // Book balance when counted, so the count stays explainable later.
            $table->decimal('book_qty', 14, 3);
            $table->decimal('variance_qty', 14, 3);
            $table->string('remarks', 500)->nullable();
            $table->string('status', 20)->default('POSTED');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'material_id']);
        });

        Schema::create('material_extra_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('boq_item_id')->nullable();
            // Set when the extra work was entered together with the material it used.
            $table->unsignedBigInteger('material_issue_id')->nullable();
            $table->string('description', 500);
            $table->decimal('qty', 14, 3);
            $table->decimal('base_qty', 14, 3);
            $table->date('work_date');
            $table->string('status', 20)->default('POSTED');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'material_id']);
        });

        // Company-wide: a supplier's record follows them from project to project.
        Schema::create('material_supplier_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('marked')->default(false);
            $table->string('marked_reason', 500)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();
            $table->unique('party_id');
        });

        // Append-only. A reason covers a gap as it stood when written; a larger gap needs a new one.
        Schema::create('material_flag_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->string('flag_type', 20);
            $table->decimal('gap_qty', 14, 3);
            $table->text('reason');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'material_id', 'flag_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_flag_reasons');
        Schema::dropIfExists('material_supplier_records');
        Schema::dropIfExists('material_extra_works');
        Schema::dropIfExists('material_stock_checks');
        Schema::dropIfExists('material_short_deliveries');
        Schema::dropIfExists('delivery_challans');
    }
};
