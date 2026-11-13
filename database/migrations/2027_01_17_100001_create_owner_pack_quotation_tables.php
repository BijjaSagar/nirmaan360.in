<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home-owner pack: a quotation for a client, the lines copied into it from the BOQ, and its
 * payment stages. The weekly update of the same pack has its own table in its own migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Once issued a quotation never changes. A change is a new revision under the same number.
        Schema::create('client_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 12)->default('draft');
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('client_name', 160);
            $table->string('language', 5)->default('en');
            // Typed for this quotation. Empty means the amounts are quoted without GST; nothing is assumed.
            $table->decimal('gst_pct', 5, 2)->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('gst_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->date('valid_until')->nullable();
            // The cover note's own sentences, the figures the document states, and their fingerprint.
            $table->json('slots')->nullable();
            $table->json('facts')->nullable();
            $table->string('fact_fingerprint', 64)->nullable();
            $table->json('number_ticks')->nullable();
            // The link a client opens. The token is in the signed address; expiry and withdrawal are read from here.
            $table->string('share_token', 40)->nullable();
            $table->timestamp('share_expires_at')->nullable();
            $table->timestamp('share_revoked_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->unique(['organization_id', 'number', 'revision']);
            $table->index(['project_id', 'status']);
        });

        // Copied from the BOQ when the quotation is prepared, so a later BOQ change does not reach a quotation already made.
        Schema::create('client_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained('client_quotations')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained('boq_items')->nullOnDelete();
            $table->string('code', 32);
            $table->text('description');
            // A translation is printed only after a person accepted that line, and always beside the original.
            $table->text('description_translated')->nullable();
            $table->foreignId('translation_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('translation_accepted_at')->nullable();
            $table->string('unit', 16);
            $table->decimal('qty', 14, 3);
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['quotation_id', 'sort']);
        });

        Schema::create('client_payment_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained('client_quotations')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('basis', 10);
            $table->decimal('percent', 7, 4)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('condition_text', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->date('reached_on')->nullable();
            $table->foreignId('reached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['quotation_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_payment_stages');
        Schema::dropIfExists('client_quotation_items');
        Schema::dropIfExists('client_quotations');
    }
};
