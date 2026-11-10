<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A broadcast or a reminder pushed to the field app. Written by platform staff (organization_id null,
        // for every company or one of them) or by a company's own admin (organization_id is that company).
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->string('title', 120);
            $table->text('body');
            // {"mr": {"title": ..., "body": ...}, "hi": {...}}. A language left out is sent in English.
            $table->json('translations')->nullable();
            // A rupee figure in any language, or the writer said so. Such a message reaches, and is shown to, only people with money.view.
            $table->boolean('mentions_money')->default(false);
            $table->string('image_path')->nullable();
            // The picture is fetched by Firebase and by the phones without signing in, so its address is this unguessable token.
            $table->string('image_token', 48)->nullable()->unique();
            // all, company, roles or project. target_organization_id limits any of them to one company.
            $table->string('audience', 16);
            $table->foreignId('target_organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->json('roles')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            // now, once, daily or weekly. Times are on the Asia/Kolkata clock.
            $table->string('schedule', 10);
            $table->string('repeat_time', 5)->nullable();
            $table->unsignedTinyInteger('repeat_weekday')->nullable();
            $table->date('repeat_until')->nullable();
            // When it was set to go: the chosen time, or a reminder's first run. Kept for the history list.
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('next_run_at')->nullable();
            // scheduled, sending, sent, cancelled; failed when a run queued nobody after its retries, partial when it queued some.
            // The run in progress or last run, so a retry of a failed one-off pushes nobody twice.
            $table->string('last_run_key', 16)->nullable();
            // Each run has a token, and each queued copy of its job an id of its own. The first copy to start takes
            // the run (started_by); any other copy of it, queued again by the sweep, then does nothing.
            $table->uuid('run_token')->nullable();
            $table->uuid('started_by')->nullable();
            $table->timestamp('run_started_at')->nullable();
            // When this one-off first queued phones. People's claims on it run from then, so "Send again" is offered
            // only while they hold, and nobody it reached is pushed twice.
            $table->timestamp('first_batch_at')->nullable();
            $table->string('status', 12);
            $table->unsignedInteger('runs')->default(0);
            $table->unsignedInteger('batches_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            // Totals over every run. Sent and failed are counted as each phone's push is delivered or refused.
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('phones_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'next_run_at']);
            $table->index(['organization_id', 'id']);
        });

        // Who an announcement reached, so each person's app lists what was sent to them. One row per person,
        // whichever company they are in: a repeating reminder moves last_sent_at instead of adding rows.
        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_sent_at');
            $table->timestamp('last_sent_at');
            $table->unique(['announcement_id', 'user_id']);
            $table->index(['user_id', 'last_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('announcements');
    }
};
