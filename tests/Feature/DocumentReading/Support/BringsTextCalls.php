<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\AiTextCall;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credits and limits for text calls are written before the text calls themselves exist. These tests
 * therefore bring the table, the ledger column and the model with them when they are missing, with
 * exactly the columns the design gives ai_text_calls. Everything made here is rolled back with the
 * test. When the real migration and model are merged, the tests run against those instead.
 */
trait BringsTextCalls
{
    protected function bringTextCalls(): void
    {
        if (! class_exists(AiTextCall::class)) {
            class_alias(TextCallStandIn::class, AiTextCall::class);
        }

        if (! Schema::hasTable('ai_text_calls')) {
            Schema::create('ai_text_calls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('project_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('purpose', 24);
                $table->string('subject_type', 40)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->uuid('client_uuid')->nullable();
                $table->string('model', 60);
                $table->string('status', 12);
                $table->string('fail_reason', 24)->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->uuid('attempt_uuid')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->decimal('max_credits', 15, 4);
                $table->decimal('credits', 15, 4)->default(0);
                $table->unsignedBigInteger('input_tokens')->default(0);
                $table->unsignedBigInteger('output_tokens')->default(0);
                $table->unsignedBigInteger('cache_read_tokens')->default(0);
                $table->unsignedBigInteger('cache_write_tokens')->default(0);
                $table->decimal('cost_usd', 12, 6)->default(0);
                $table->boolean('cost_estimated')->default(false);
                $table->string('stop_reason', 30)->nullable();
                $table->string('provider_message_id')->nullable();
                $table->string('provider_request_id')->nullable();
                $table->unsignedInteger('latency_ms')->default(0);
                $table->unsignedInteger('input_chars')->default(0);
                $table->timestamps();
                $table->unique(['organization_id', 'client_uuid']);
            });
        }

        if (! Schema::hasColumn('credit_ledger', 'ai_text_call_id')) {
            Schema::table('credit_ledger', function (Blueprint $table) {
                $table->unsignedBigInteger('ai_text_call_id')->nullable();
            });
        }
    }

    /** A text call row with every column the design lists filled in, so it fits the real table too. */
    protected function textCall(Organization $organization, ?User $by = null, array $overrides = []): Model
    {
        $id = DB::table('ai_text_calls')->insertGetId([
            'organization_id' => $organization->id,
            'user_id' => $by?->id,
            'purpose' => 'wording',
            'model' => 'claude-sonnet-5-5',
            'status' => 'running',
            'attempts' => 1,
            'max_credits' => '3.0000',
            'credits' => '0',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'cost_usd' => '0',
            'cost_estimated' => false,
            'latency_ms' => 0,
            'input_chars' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return AiTextCall::withoutGlobalScopes()->findOrFail($id);
    }
}
