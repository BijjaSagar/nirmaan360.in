<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A dry run of a reader or of a text call by the platform's own staff, on a document they
         * uploaded for the test. Neither table has an organization_id: a dry run belongs to no
         * company, charges no company and creates nothing in any company.
         */
        Schema::create('reader_trials', function (Blueprint $table) {
            $table->id();
            // Names the trial's folder on the disk, so a path never carries a guessable number.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // One of the two: a reader kind with its pass, or the purpose of a text call.
            $table->string('kind', 24)->nullable();
            $table->string('purpose', 24)->nullable();
            // For a purpose several features share: the record type whose handler is tried.
            $table->string('subject_type', 40)->nullable();
            $table->string('pass', 16)->nullable();
            // What is sent: page pictures, or pasted text.
            $table->string('material', 8)->default('pictures');
            $table->string('model', 60);
            $table->string('effort', 10);
            $table->unsignedInteger('max_tokens');
            $table->unsignedSmallInteger('timeout');
            $table->string('status', 16)->default('draft');
            $table->string('pause_reason', 24)->nullable();
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            // Counts of cells and of cells marked wrong, by field and by digit script. Never a value.
            $table->json('score')->nullable();
            // The document's main language, as a code, when the admin named it.
            $table->json('options')->nullable();
            // When the admin ticked that the document may be used for testing.
            $table->timestamp('allowed_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // The files and what the model returned are deleted at this moment. Counts, tokens and cost stay.
            $table->timestamp('purge_after');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
            $table->index(['purged_at', 'purge_after']);
        });

        // One call to the model in a dry run: a page picture, or one pasted text.
        Schema::create('reader_trial_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reader_trial_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_no');
            $table->string('pass', 16)->default('main');
            $table->string('image_path', 500)->nullable();
            $table->char('image_sha256', 64)->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->unsignedInteger('image_bytes')->nullable();
            $table->string('text_path', 500)->nullable();
            $table->unsignedInteger('text_chars')->nullable();
            $table->string('model', 60);
            $table->string('status', 16)->default('waiting');
            $table->string('fail_reason', 24)->nullable();
            $table->string('error_detail', 300)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('requeues')->default(0);
            // Answers of a text call that its own checks refused.
            $table->unsignedTinyInteger('refused')->default(0);
            $table->uuid('attempt_uuid')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('not_before')->nullable();
            // Which rules a refused answer broke, by code, for the one corrective request. Never the answer itself.
            $table->json('correction')->nullable();
            // What the model returned, and which of its cells the admin marked wrong. Both are deleted with the files.
            $table->json('result')->nullable();
            $table->json('marks')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->boolean('cost_estimated')->default(false);
            $table->string('stop_reason', 30)->nullable();
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['reader_trial_id', 'page_no', 'pass']);
            // Tending looks for pages that are waiting or lost.
            $table->index(['status', 'claimed_at']);
            // The day's allowance for dry runs and the month's total add up cost by the day it arose.
            $table->index('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reader_trial_pages');
        Schema::dropIfExists('reader_trials');
    }
};
