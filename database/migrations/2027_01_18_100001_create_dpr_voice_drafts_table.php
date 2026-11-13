<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One spoken daily report: the words the phone heard, and the proposal made from them for the
         * person to check on the phone. It never becomes a DPR by itself: the phone fills its own form
         * and sends the DPR through its outbox, and then names that DPR here.
         *
         * The words and the proposal are blanked after a time (text_purged_at), and when the person who
         * spoke them is deleted. The row stays, because its text call carries the credits it used.
         */
        Schema::create('dpr_voice_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid');
            $table->date('report_date');
            // mr-IN, hi-IN or en-IN: the language the phone listened in.
            $table->string('locale', 5);
            $table->text('transcript')->nullable();
            // What the app reports about how the words were heard: the engine, the system, whether it was checked to work offline.
            $table->json('speech')->nullable();
            $table->string('platform', 10);
            $table->string('app_version', 20);
            $table->timestamp('recorded_at')->nullable();
            // queued, ready or failed.
            $table->string('status', 14)->default('queued');
            $table->json('proposal')->nullable();
            // The BOQ lines offered to the model, by ref, and which words backed each figure. Never a rate.
            $table->json('checks')->nullable();
            $table->string('fail_reason', 24)->nullable();
            // The text call of the document reading module, so no foreign key.
            $table->unsignedBigInteger('ai_text_call_id')->nullable();
            // The DPR the phone made from the proposal, by the client_uuid it was sent with.
            $table->uuid('dpr_client_uuid')->nullable();
            // The fields the person changed by hand before sending, such as "work.0.qty".
            $table->json('changed')->nullable();
            $table->timestamp('text_purged_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'user_id']);
            // Retention looks for words that are past their time, across every company.
            $table->index(['text_purged_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpr_voice_drafts');
    }
};
