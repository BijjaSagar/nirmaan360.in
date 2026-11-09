<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('worker_scope', 16)->default('all');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status')->default('DRAFT');
            $table->json('terms')->nullable();
            $table->decimal('total_days', 14, 3)->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_recovery', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_note')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('wage_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wage_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained();
            $table->string('worker_name');
            $table->string('trade');
            $table->string('worker_type', 16)->default('own');
            $table->decimal('daily_wage', 15, 2);
            $table->decimal('present_days', 14, 3)->default(0);
            $table->decimal('half_days', 14, 3)->default(0);
            $table->decimal('absent_days', 14, 3)->default(0);
            $table->decimal('payable_days', 14, 3)->default(0);
            $table->decimal('ot_hours', 14, 3)->default(0);
            $table->decimal('day_pay', 15, 2)->default(0);
            $table->decimal('ot_pay', 15, 2)->default(0);
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('advance_recovery', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['wage_run_id', 'worker_id']);
        });

        Schema::create('worker_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained();
            $table->decimal('amount', 15, 2);
            $table->date('given_on');
            // The most taken from one wage run. Zero means as much as the wages allow.
            $table->decimal('recovery_per_run', 15, 2)->default(0);
            $table->string('reason')->nullable();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'worker_id']);
        });

        Schema::create('advance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wage_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wage_run_line_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('staff_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('leave_type', 24);
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 14, 3);
            $table->string('reason')->nullable();
            $table->string('status')->default('PENDING');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('claim_date');
            $table->string('category', 40);
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->string('status')->default('PENDING');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('staff_leaves');
        Schema::dropIfExists('advance_recoveries');
        Schema::dropIfExists('worker_advances');
        Schema::dropIfExists('wage_run_lines');
        Schema::dropIfExists('wage_runs');
    }
};
