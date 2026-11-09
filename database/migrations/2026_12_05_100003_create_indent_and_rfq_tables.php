<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('material_stores');
            $table->string('number', 40);
            $table->date('required_by')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('indent_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained();
            $table->decimal('qty', 14, 3);
            $table->foreignId('unit_id')->constrained('material_units');
            $table->decimal('base_qty', 14, 3);
            $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indent_id')->constrained();
            $table->string('number', 40);
            $table->date('due_date')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('rfq_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            $table->string('status', 20)->default('INVITED');
            $table->string('quote_ref', 60)->nullable();
            $table->date('quote_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->string('payment_terms', 160)->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['rfq_id', 'party_id']);
        });

        Schema::create('rfq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indent_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_id')->constrained();
            $table->decimal('qty', 14, 3);
            $table->foreignId('unit_id')->constrained('material_units');
            $table->decimal('base_qty', 14, 3);
            $table->foreignId('awarded_quotation_id')->nullable()->constrained('rfq_quotations')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rfq_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 15, 2);
            $table->decimal('gst_pct', 5, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->decimal('gst_amount', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['rfq_quotation_id', 'rfq_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_quotation_items');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfq_quotations');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('indent_items');
        Schema::dropIfExists('indents');
    }
};
