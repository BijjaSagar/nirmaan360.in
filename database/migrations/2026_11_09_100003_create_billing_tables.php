<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type');
            $table->json('levels');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'doc_type']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('approvable');
            $table->string('doc_type');
            $table->string('status')->default('IN_REVIEW');
            $table->unsignedTinyInteger('current_level')->default(1);
            $table->json('levels');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(0);
            $table->string('role')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->text('remark')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            $table->string('number', 40);
            $table->string('title');
            $table->text('scope')->nullable();
            $table->string('rate_type')->default('item_rate');
            $table->string('status')->default('ACTIVE');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('retention_pct', 5, 2)->default(5);
            $table->decimal('tds_pct', 5, 2)->default(1);
            $table->decimal('gst_pct', 5, 2)->default(18);
            $table->decimal('advance_amount', 15, 2)->default(0);
            $table->decimal('advance_recovery_pct', 5, 2)->default(0);
            $table->json('deduction_order')->nullable();
            $table->string('deduction_basis')->default('gross');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 14, 3)->default(0);
            $table->decimal('rate', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['work_order_id', 'boq_item_id']);
        });

        Schema::create('ra_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('type');
            $table->unsignedInteger('bill_no');
            $table->string('number', 60);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status')->default('DRAFT');
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('deduction_total', 15, 2)->default(0);
            $table->decimal('gst_amount', 15, 2)->default(0);
            $table->decimal('net_payable', 15, 2)->default(0);
            $table->json('terms')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['project_id', 'type']);
        });

        Schema::create('ra_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ra_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained();
            $table->string('code', 32);
            $table->text('description');
            $table->string('unit', 16);
            $table->decimal('rate', 15, 2);
            $table->decimal('prev_qty', 14, 3)->default(0);
            $table->decimal('current_qty', 14, 3)->default(0);
            $table->decimal('cumulative_qty', 14, 3)->default(0);
            $table->decimal('prev_amount', 15, 2)->default(0);
            $table->decimal('current_amount', 15, 2)->default(0);
            $table->decimal('cumulative_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('ra_bill_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ra_bill_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            $table->decimal('pct', 5, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('measurement_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('measured_on');
            $table->string('description')->nullable();
            $table->string('location')->nullable();
            $table->decimal('nos', 14, 3)->default(1);
            $table->decimal('length', 14, 3)->nullable();
            $table->decimal('breadth', 14, 3)->nullable();
            $table->decimal('depth', 14, 3)->nullable();
            $table->boolean('is_deduction')->default(false);
            $table->decimal('qty', 14, 3)->default(0);
            $table->string('status')->default('DRAFT');
            $table->boolean('deviation')->default(false);
            $table->foreignId('reversal_of_id')->nullable()->constrained('measurement_entries')->nullOnDelete();
            $table->foreignId('billed_client_bill_id')->nullable()->constrained('ra_bills')->nullOnDelete();
            $table->foreignId('billed_sub_bill_id')->nullable()->constrained('ra_bills')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
            $table->index(['boq_item_id', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('measurement_entries');
        Schema::dropIfExists('ra_bill_deductions');
        Schema::dropIfExists('ra_bill_items');
        Schema::dropIfExists('ra_bills');
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_rules');
    }
};
