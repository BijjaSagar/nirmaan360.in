<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One document handed to a reader: its pages, what it may cost and how far it has got.
        Schema::create('document_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Null is a company-level read, such as a tender document.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            // The record of another module this read belongs to, so no foreign key.
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('kind', 24);
            $table->string('status', 16)->default('draft');
            $table->string('pause_reason', 24)->nullable();
            $table->string('channel', 10)->default('web');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->string('source_name', 160)->nullable();
            $table->string('source_path', 500)->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->unsignedBigInteger('source_bytes')->default(0);
            $table->string('text_path', 500)->nullable();
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_read')->default(0);
            $table->unsignedInteger('pages_failed')->default(0);
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->unsignedTinyInteger('resumes')->default(0);
            // Credits and provider cost are usage units, finer than invoice money.
            $table->decimal('credits_estimate', 15, 4)->default(0);
            $table->decimal('credits_cap', 15, 4)->default(0);
            $table->decimal('credits_held', 15, 4)->default(0);
            $table->decimal('credits_used', 15, 4)->default(0);
            $table->decimal('credits_burned', 15, 4)->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('absorbed_usd', 12, 6)->default(0);
            $table->string('consent_version', 20)->nullable();
            $table->json('options')->nullable();
            $table->json('review')->nullable();
            $table->string('error', 200)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('prepare_started_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('files_purged_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        // One call to the model: a page picture, half of one, or a run of text pages.
        Schema::create('document_read_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Always the read's own project, copied when the row is made.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_read_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_no');
            $table->unsignedInteger('page_to')->nullable();
            $table->string('pass', 16)->default('main');
            $table->string('part', 6)->default('full');
            $table->string('status', 16)->default('waiting');
            $table->string('fail_reason', 24)->nullable();
            $table->string('error_detail', 300)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->char('image_sha256', 64)->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->unsignedInteger('image_bytes')->nullable();
            // Where a half starts and how tall it is, per thousand of the full page.
            $table->unsignedSmallInteger('part_top')->nullable();
            $table->unsignedSmallInteger('part_height')->nullable();
            $table->unsignedInteger('text_chars')->nullable();
            $table->boolean('needs_picture')->default(false);
            $table->string('model', 60);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('behaviour_failures')->default(0);
            $table->unsignedTinyInteger('requeues')->default(0);
            $table->unsignedTinyInteger('auto_requeued')->default(0);
            $table->uuid('attempt_uuid')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('not_before')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->boolean('cost_estimated')->default(false);
            $table->decimal('credits', 15, 4)->default(0);
            $table->string('stop_reason', 30)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->json('result')->nullable();
            $table->json('injection_flags')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['document_read_id', 'page_no', 'pass', 'part']);
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'claimed_at']);
            $table->index(['organization_id', 'finished_at']);
            // The platform's daily and monthly limits add up every company's pages by day.
            $table->index('finished_at');
        });

        // A company's consent to send documents to the AI provider, and its own spending limits.
        Schema::create('ai_document_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('consent_version', 20)->nullable();
            $table->text('consent_text')->nullable();
            $table->string('consent_locale', 5)->nullable();
            $table->foreignId('consented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consented_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->decimal('max_credits_per_read', 15, 4)->nullable();
            $table->json('budgets')->nullable();
            $table->boolean('platform_block')->default(false);
            $table->foreignId('quota_lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quota_lifted_at')->nullable();
            $table->timestamps();
        });

        // A reader charge names its read, and carries a reference that can be written only once.
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('document_read_id')->nullable();
            $table->string('reference', 80)->nullable();
            $table->unique(['organization_id', 'reference']);
        });
    }

    public function down(): void
    {
        // The index goes first: SQLite cannot drop a column that an index still uses.
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
        });

        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropColumn(['document_read_id', 'reference']);
        });

        Schema::dropIfExists('document_read_pages');
        Schema::dropIfExists('document_reads');
        Schema::dropIfExists('ai_document_settings');
    }
};
