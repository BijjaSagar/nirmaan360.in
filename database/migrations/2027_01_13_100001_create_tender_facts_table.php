<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One thing a tender says, as the reader found it on one page: a date, a deposit, a period, a
        // document to submit. A person confirms or rejects each one; nothing reaches the tender by itself.
        // The tender, its document and the read are ids without a foreign key, as for the tender's BOQ lines.
        Schema::create('crm_tender_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tender_id')->index();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('document_read_id')->nullable()->index();
            $table->string('field', 40);
            $table->string('label', 160)->nullable();
            $table->text('value_raw');
            $table->string('value_norm', 255)->nullable();
            $table->string('value_kind', 12);
            $table->unsignedInteger('page');
            $table->text('quote');
            $table->text('quote_line')->nullable();
            // Null when the fact was read from a page picture, where there is no text to match.
            $table->boolean('quote_found')->nullable();
            $table->string('source', 8);
            $table->string('status', 12)->default('proposed');
            $table->uuid('conflict_group')->nullable();
            $table->boolean('edited')->default(false);
            $table->boolean('page_viewed')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('applied_to', 60)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'tender_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tender_facts');
    }
};
