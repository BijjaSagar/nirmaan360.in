<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide catalogue, so these two have no organization_id.
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name');
            $table->text('description');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->char('content_hash', 64);
            $table->text('system_prompt');
            $table->json('tools');
            $table->string('permission', 60);
            $table->string('model', 60);
            $table->string('effort', 10);
            $table->string('default_autonomy', 2);
            $table->string('max_autonomy', 2);
            $table->unsignedInteger('max_steps');
            $table->unsignedInteger('max_seconds');
            // Credits and vendor cost are usage units, finer than invoice money.
            $table->decimal('max_credits', 15, 4);
            $table->json('output_schema');
            $table->timestamps();
            $table->unique(['agent_id', 'version']);
        });

        Schema::create('agent_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('autonomy', 2)->nullable();
            $table->unsignedInteger('max_steps')->nullable();
            $table->unsignedInteger('max_seconds')->nullable();
            $table->decimal('max_credits', 15, 4)->nullable();
            $table->boolean('daily_brief')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'agent_id']);
        });

        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'agent_id']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20)->default('web');
            $table->string('status')->default('QUEUED');
            $table->string('autonomy', 2);
            $table->string('model', 60);
            $table->text('input');
            $table->json('messages');
            $table->text('answer')->nullable();
            $table->json('output')->nullable();
            $table->json('sources')->nullable();
            $table->string('limit_reason', 20)->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('max_steps');
            $table->unsignedInteger('max_seconds');
            $table->decimal('max_credits', 15, 4);
            $table->unsignedInteger('steps_count')->default(0);
            $table->unsignedTinyInteger('correction_attempts')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('credits_used', 15, 4)->default(0);
            $table->unsignedBigInteger('active_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'user_id', 'id']);
        });

        Schema::create('agent_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('purpose', 20)->default('agent');
            $table->string('model', 60);
            $table->string('stop_reason', 30)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('credits', 15, 4)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('text')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();
            $table->unique(['agent_run_id', 'number']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_step_id')->constrained()->cascadeOnDelete();
            $table->string('tool_use_id', 120);
            $table->string('tool', 80);
            $table->string('status')->default('PENDING');
            $table->string('refusal_code', 30)->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->json('sources')->nullable();
            $table->json('injection_flags')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamps();
            $table->unique(['agent_run_id', 'tool_use_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('agent_action_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_tool_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool', 80);
            $table->string('summary');
            $table->json('input');
            $table->string('status')->default('DRAFT');
            $table->timestamps();
        });

        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('included_balance', 15, 4)->default(0);
            $table->decimal('purchased_balance', 15, 4)->default(0);
            $table->string('included_period', 7)->nullable();
            $table->timestamps();
        });

        Schema::create('credit_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pack', 20);
            $table->decimal('credits', 15, 4);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('gst_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->string('status')->default('created');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('credits', 15, 4);
            $table->decimal('balance_after', 15, 4);
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_topup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('credit_topups');
        Schema::dropIfExists('credit_wallets');
        Schema::dropIfExists('agent_action_requests');
        Schema::dropIfExists('agent_tool_calls');
        Schema::dropIfExists('agent_steps');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('agent_installs');
        Schema::dropIfExists('agent_versions');
        Schema::dropIfExists('agents');
    }
};
