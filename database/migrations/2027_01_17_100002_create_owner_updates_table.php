<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home-owner pack: the weekly update a contractor prepares for a client from the week's approved
 * daily reports. Nothing here is sent by the app: a named person approves it and shares a link.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Once approved an update never changes. Only its share link may be made, replaced or withdrawn.
        Schema::create('owner_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The issued quotation whose payment position the update states, when it states one.
            $table->foreignId('quotation_id')->nullable()->constrained('client_quotations')->nullOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->string('language', 5)->default('en');
            $table->string('status', 12)->default('draft');
            $table->boolean('show_money')->default(false);
            // Typed by the contractor on this update. The pack keeps no receipts of its own, and nothing is assumed when it is empty.
            $table->decimal('received_amount', 15, 2)->nullable();
            // The day the figures were last read from the records: the "as on" date the update prints.
            $table->date('prepared_on')->nullable();
            // The figures the update states and their fingerprint, the free sentences, and the text as it was approved.
            $table->json('facts')->nullable();
            $table->string('fact_fingerprint', 64)->nullable();
            $table->json('slots')->nullable();
            $table->json('body_rendered')->nullable();
            $table->json('photo_ids')->nullable();
            $table->text('next_week_text')->nullable();
            $table->json('typed_numbers')->nullable();
            $table->json('number_ticks')->nullable();
            // The link a client opens. The token is in the signed address; expiry and withdrawal are read from here.
            $table->string('share_token', 40)->nullable();
            $table->timestamp('share_expires_at')->nullable();
            $table->timestamp('share_revoked_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_updates');
    }
};
