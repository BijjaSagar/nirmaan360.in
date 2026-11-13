<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One press of "Suggest rates" on a project's BOQ, for the lines the person picked.
        Schema::create('rate_suggestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // open: lines still wait for a person. done: every line was accepted or skipped.
            $table->string('status', 12)->default('open');
            $table->uuid('client_uuid');
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'id']);
        });

        // One picked BOQ line and the shortlist found for it.
        Schema::create('rate_suggestion_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('rate_suggestion_runs')->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained('boq_items')->cascadeOnDelete();
            /*
             * The shortlist: for each candidate its ref on this line, the source's key, the id of the
             * source's row, the score, and the two fields a later ranking fills in. It never holds a
             * rate, a description, or the name of a project or tender: those are read from the source
             * row each time, as the person who is looking.
             */
            $table->json('candidates');
            $table->string('accepted_ref', 12)->nullable();
            $table->string('accepted_source', 40)->nullable();
            $table->decimal('accepted_rate', 15, 2)->nullable();
            // set_boq_rate or note_current_rate: what accepting did with the rate.
            $table->string('accepted_mode', 20)->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('skipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'boq_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_suggestion_lines');
        Schema::dropIfExists('rate_suggestion_runs');
    }
};
