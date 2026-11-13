<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One request to the model that sends text and gets a small answer back: the free sentences
         * of a letter, a ranking, a grouping. The row says that it happened, what it cost and how it
         * ended. It holds neither what was sent nor what came back.
         */
        Schema::create('ai_text_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // The project of the record the call is about. Null is a company-level call.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 24);
            // The record of another module this call is about, so no foreign key.
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->string('model', 60);
            $table->string('status', 12)->default('queued');
            $table->string('fail_reason', 24)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('attempt_uuid')->nullable();
            $table->timestamp('claimed_at')->nullable();
            // Which rules an answer broke, by code, when it was refused and asked for once more. Never the answer itself.
            $table->json('correction')->nullable();
            // Credits and provider cost are usage units, finer than invoice money.
            $table->decimal('max_credits', 15, 4);
            $table->decimal('credits', 15, 4)->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            // The part of the cost nobody was charged for: every attempt but the one whose answer was kept, and what its charge could not cover.
            $table->decimal('absorbed_usd', 12, 6)->default(0);
            $table->boolean('cost_estimated')->default(false);
            $table->string('stop_reason', 30)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('input_chars')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            // A day's budget and a company's limits add up its calls by day; what is on hold is found by status.
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'status']);
            // Tending looks for calls that are waiting or lost, across every company.
            $table->index(['status', 'claimed_at']);
            // The platform's daily and monthly limits add up every company's calls by day.
            $table->index('created_at');
        });

        // A text call's charge names its call, as a reader's charge names its read.
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_text_call_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger', function (Blueprint $table) {
            $table->dropColumn('ai_text_call_id');
        });

        Schema::dropIfExists('ai_text_calls');
    }
};
