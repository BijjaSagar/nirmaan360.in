<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('material_stores');
            $table->string('number', 40);
            $table->date('issue_date');
            $table->string('issued_to', 160)->nullable();
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
            $table->index(['project_id', 'issue_date']);
        });

        Schema::create('material_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained();
            $table->decimal('qty', 14, 3);
            $table->foreignId('unit_id')->constrained('material_units');
            $table->decimal('base_qty', 14, 3);
            $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('activity', 160)->nullable();
            $table->timestamps();
        });

        Schema::create('material_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('material_stores');
            $table->string('return_type', 20);
            $table->foreignId('material_issue_id')->nullable()->constrained();
            $table->foreignId('grn_id')->nullable()->constrained();
            $table->string('number', 40);
            $table->date('return_date');
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('POSTED');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'return_date']);
        });

        Schema::create('material_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_issue_item_id')->nullable()->constrained();
            $table->foreignId('grn_item_id')->nullable()->constrained();
            $table->foreignId('material_id')->constrained();
            $table->decimal('qty', 14, 3);
            $table->foreignId('unit_id')->constrained('material_units');
            $table->decimal('base_qty', 14, 3);
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_project_id')->constrained('projects');
            $table->foreignId('to_project_id')->constrained('projects');
            $table->foreignId('from_store_id')->constrained('material_stores');
            $table->foreignId('to_store_id')->constrained('material_stores');
            $table->string('number', 40);
            $table->date('dispatch_date');
            $table->date('receive_date')->nullable();
            $table->string('vehicle_no', 30)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('DISPATCHED');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['from_project_id', 'status']);
            $table->index(['to_project_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained();
            $table->decimal('qty', 14, 3);
            $table->foreignId('unit_id')->constrained('material_units');
            $table->decimal('base_qty', 14, 3);
            $table->decimal('received_base_qty', 14, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('material_return_items');
        Schema::dropIfExists('material_returns');
        Schema::dropIfExists('material_issue_items');
        Schema::dropIfExists('material_issues');
    }
};
