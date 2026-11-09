<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('title', 160);
            $table->text('scope')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->string('award_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('contractor_tender_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            // Copied from the BOQ, so old comparisons stay right after the BOQ changes.
            $table->decimal('qty', 14, 3);
            $table->decimal('boq_rate', 15, 2);
            $table->timestamps();
            $table->unique(['contractor_tender_id', 'boq_item_id']);
        });

        // Quotes are never edited or deleted. A revision is a new row that supersedes the old one.
        Schema::create('contractor_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            $table->decimal('quoted_amount', 15, 2);
            $table->date('received_on');
            $table->string('remarks', 500)->nullable();
            $table->boolean('is_selected')->default(false);
            $table->foreignId('superseded_by_id')->nullable()->constrained('contractor_bids')->nullOnDelete();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['contractor_tender_id', 'party_id']);
        });

        Schema::create('contractor_bid_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_bid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_tender_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['contractor_bid_id', 'contractor_tender_item_id']);
        });

        Schema::create('contractor_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('sort')->default(0);
            $table->date('done_on')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['work_order_id', 'sort']);
        });

        // Separate from vendor_payments and RA bill settlement: nothing here is added to those, or they to this.
        Schema::create('contractor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties');
            $table->string('number', 40);
            $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contractor_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->date('paid_on');
            // Negative only on a reversal, so history is never rewritten.
            $table->decimal('amount', 15, 2);
            $table->string('mode', 10);
            $table->string('paid_to', 160);
            $table->string('reference', 60)->nullable();
            $table->boolean('is_advance')->default(false);
            $table->string('notes', 1000)->nullable();
            $table->string('bill_path')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('contractor_payments')->nullOnDelete();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
            $table->index(['work_order_id', 'status']);
            $table->index('party_id');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('deadline_label', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('deadline_label');
        });

        Schema::dropIfExists('contractor_payments');
        Schema::dropIfExists('contractor_milestones');
        Schema::dropIfExists('contractor_bid_items');
        Schema::dropIfExists('contractor_bids');
        Schema::dropIfExists('contractor_tender_items');
        Schema::dropIfExists('contractor_tenders');
    }
};
