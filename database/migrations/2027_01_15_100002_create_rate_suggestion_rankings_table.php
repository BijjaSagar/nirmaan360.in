<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One press of "Rank with AI" on a run is sent in parts of a few lines each, and each part is
         * one text call. A part keeps what is sent (the descriptions and units of its lines and their
         * candidates, read as the person who pressed) only while its call is open, because the call
         * runs on the queue with nobody signed in. Never a rate. The answer is kept on the run's lines.
         */
        Schema::create('rate_suggestion_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // No foreign key: the rate helper's first migration is rolled back on its own in its tests, and a key here
            // would stop it dropping its runs. A run goes only with its project, and the project's key removes these rows.
            $table->unsignedBigInteger('run_id');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // The press: every part of one press carries its client_uuid and its own number.
            $table->uuid('client_uuid')->nullable();
            $table->unsignedSmallInteger('part')->default(1);
            // The language the notes are written in: the language of the person who pressed.
            $table->string('locale', 2);
            $table->unsignedSmallInteger('lines')->default(0);
            $table->json('material')->nullable();
            // asked: sent, or about to be. ranked: the answer was kept on the lines. failed: nothing was kept; reason says why.
            $table->string('status', 12)->default('asked');
            $table->string('reason', 24)->nullable();
            $table->unsignedBigInteger('ai_text_call_id')->nullable();
            $table->timestamp('ranked_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid', 'part']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_suggestion_rankings');
    }
};
