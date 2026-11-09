<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            // Owned by the procurement module, so these stay plain ids with no foreign key.
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('grn_id')->nullable();
            $table->string('bill_no', 60);
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->string('cost_head', 60);
            $table->text('description')->nullable();
            $table->string('supply_type', 8)->default('intra');
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('gst_pct', 5, 2)->default(0);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('tds_section', 10)->nullable();
            $table->decimal('tds_pct', 5, 2)->default(0);
            $table->decimal('tds_amount', 15, 2)->default(0);
            $table->decimal('net_payable', 15, 2)->default(0);
            $table->string('status')->default('DRAFT');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('vendor_bills')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
            $table->index(['organization_id', 'bill_date']);
            $table->index(['party_id', 'bill_no']);
        });

        // A payment settles one vendor bill or one subcontractor RA bill.
        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('vendor_bill_id')->nullable()->constrained('vendor_bills')->cascadeOnDelete();
            $table->foreignId('ra_bill_id')->nullable()->constrained('ra_bills')->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->string('mode', 12)->default('bank');
            $table->string('reference', 60)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('POSTED');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('vendor_payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'paid_on']);
        });

        Schema::create('client_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('ra_bill_id')->constrained('ra_bills')->cascadeOnDelete();
            $table->date('received_on');
            $table->decimal('amount', 15, 2);
            $table->decimal('tds_amount', 15, 2)->default(0);
            $table->string('mode', 12)->default('bank');
            $table->string('reference', 60)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status')->default('POSTED');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('client_receipts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'received_on']);
        });

        Schema::create('gst_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ra_bill_id')->constrained('ra_bills')->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('financial_year', 7);
            $table->unsignedInteger('serial');
            $table->string('invoice_no', 16);
            $table->date('invoice_date');
            $table->string('supplier_name');
            $table->string('supplier_gstin', 15);
            $table->text('supplier_address')->nullable();
            $table->string('buyer_name');
            $table->string('buyer_gstin', 15)->nullable();
            $table->text('buyer_address')->nullable();
            $table->string('place_of_supply_code', 2);
            $table->string('place_of_supply', 60);
            $table->string('supply_type', 8);
            $table->string('sac', 8);
            $table->string('description');
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('gst_pct', 5, 2);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);
            $table->decimal('invoice_value', 15, 2);
            $table->string('status')->default('ISSUED');
            // Filled in by hand after the e-invoice portal gives them. This app never calls the portal.
            $table->string('irn', 64)->nullable();
            $table->string('irn_ack_no', 20)->nullable();
            $table->dateTime('irn_ack_date')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'invoice_no']);
            $table->unique(['organization_id', 'financial_year', 'serial']);
            $table->index(['organization_id', 'invoice_date']);
            $table->index(['ra_bill_id', 'status']);
        });

        Schema::create('finance_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('cost_head', 60);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'cost_head']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_budget_lines');
        Schema::dropIfExists('gst_invoices');
        Schema::dropIfExists('client_receipts');
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('vendor_bills');
    }
};
