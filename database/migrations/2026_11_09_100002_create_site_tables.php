<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dprs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->date('report_date');
            $table->string('weather')->nullable();
            $table->text('summary')->nullable();
            $table->text('hindrances')->nullable();
            $table->string('status')->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'report_date']);
        });

        Schema::create('dpr_manpower', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dpr_id')->constrained()->cascadeOnDelete();
            $table->string('trade');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();
        });

        Schema::create('dpr_machinery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dpr_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('hours', 6, 1)->default(0);
            $table->timestamps();
        });

        Schema::create('dpr_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dpr_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('qty', 14, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('dpr_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dpr_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->timestamps();
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority')->default('medium');
            $table->string('status')->default('open');
            $table->string('location_note')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('trade');
            $table->string('type')->default('own');
            $table->foreignId('contractor_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->decimal('daily_wage', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status')->default('present');
            $table->decimal('ot_hours', 4, 1)->default(0);
            $table->string('method')->default('manual');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'worker_id', 'attendance_date']);
        });

        Schema::create('petty_cash_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('petty_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('petty_cash_wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->string('category')->nullable();
            $table->string('description')->nullable();
            $table->string('bill_path')->nullable();
            $table->date('entry_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_entries');
        Schema::dropIfExists('petty_cash_wallets');
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('workers');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('dpr_photos');
        Schema::dropIfExists('dpr_work_items');
        Schema::dropIfExists('dpr_machinery');
        Schema::dropIfExists('dpr_manpower');
        Schema::dropIfExists('dprs');
    }
};
