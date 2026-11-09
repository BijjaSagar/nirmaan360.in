<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            // Random token printed in the QR tag. The tag never carries the code or the company.
            $table->string('tag', 24)->unique();
            $table->string('name');
            $table->string('category', 40);
            $table->string('make', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('registration_no', 40)->nullable();
            $table->string('serial_no', 60)->nullable();
            $table->string('ownership', 10)->default('owned');
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 15, 2)->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('hire_basis', 10)->nullable();
            $table->decimal('hire_rate', 15, 2)->nullable();
            $table->decimal('min_hours_per_month', 14, 3)->nullable();
            // dry: we supply fuel, the rate has none. wet: the owner supplies it, so fuel we issue is recovered.
            $table->string('hire_type', 10)->default('dry');
            $table->date('hire_from')->nullable();
            $table->date('hire_to')->nullable();
            $table->string('meter_unit', 10)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_note', 160)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('from_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('from_location', 160)->nullable();
            $table->foreignId('to_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('to_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('to_location', 160)->nullable();
            $table->date('moved_on');
            $table->string('reason')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['asset_id', 'moved_on']);
        });

        Schema::create('asset_hire_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->unsignedInteger('seq');
            $table->string('number', 30);
            $table->string('vendor_invoice_no', 60)->nullable();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('basis', 10);
            $table->decimal('rate', 15, 2);
            $table->decimal('actual_quantity', 14, 3)->default(0);
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->string('hire_type', 10)->default('dry');
            $table->decimal('fuel_litres', 14, 3)->default(0);
            $table->decimal('fuel_recovery', 15, 2)->default(0);
            $table->decimal('other_deduction', 15, 2)->default(0);
            $table->string('other_deduction_note')->nullable();
            $table->decimal('gst_pct', 5, 2)->default(0);
            $table->decimal('gst_amount', 15, 2)->default(0);
            $table->decimal('tds_pct', 5, 2)->default(0);
            $table->decimal('tds_amount', 15, 2)->default(0);
            $table->decimal('net_payable', 15, 2)->default(0);
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('finalized_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 80)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'seq']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['asset_id', 'period_from']);
        });

        Schema::create('machine_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('log_date');
            $table->decimal('opening_reading', 14, 3)->nullable();
            $table->decimal('closing_reading', 14, 3)->nullable();
            $table->decimal('hours', 14, 3)->default(0);
            $table->decimal('km', 14, 3)->default(0);
            $table->decimal('breakdown_hours', 14, 3)->default(0);
            $table->string('operator', 80)->nullable();
            $table->string('remarks')->nullable();
            // Set while the log is part of a hire bill, so it cannot be billed twice or deleted.
            $table->foreignId('hire_bill_id')->nullable()->constrained('asset_hire_bills')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['asset_id', 'log_date']);
            $table->index(['organization_id', 'log_date']);
        });

        Schema::create('fuel_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('issue_date');
            $table->decimal('litres', 14, 3);
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('reading', 14, 3)->nullable();
            $table->string('source', 80)->nullable();
            $table->string('slip_no', 40)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['asset_id', 'issue_date']);
        });

        Schema::create('asset_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reported_at');
            $table->text('description');
            $table->string('cause')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('repair_cost', 15, 2)->default(0);
            $table->text('resolution')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['asset_id', 'status']);
        });

        Schema::create('asset_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            // Hours or km of work between services, in the asset's meter unit.
            $table->decimal('interval_usage', 14, 3)->nullable();
            $table->unsignedInteger('interval_days')->nullable();
            $table->date('last_done_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['asset_id', 'is_active']);
        });

        Schema::create('asset_service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_id')->nullable()->constrained('asset_maintenance')->nullOnDelete();
            $table->date('done_on');
            $table->string('description')->nullable();
            $table->string('done_by', 120)->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['asset_id', 'done_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_service_records');
        Schema::dropIfExists('asset_maintenance');
        Schema::dropIfExists('asset_breakdowns');
        Schema::dropIfExists('fuel_issues');
        Schema::dropIfExists('machine_logs');
        Schema::dropIfExists('asset_hire_bills');
        Schema::dropIfExists('asset_movements');
        Schema::dropIfExists('assets');
    }
};
