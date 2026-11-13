<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A letter drafted from the register or from unpaid bills. Every figure in it is a fact read from the records.
        Schema::create('site_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // eot_request | delay_notice | payment_followup
            $table->string('type', 24);
            $table->string('language', 2);
            // draft | finalised
            $table->string('status', 12);
            // What the person chose that is not a record of its own: the bills, and a completion date they typed.
            $table->json('inputs')->nullable();
            // The fact sheet the draft was made from, and its fingerprint.
            $table->json('facts');
            $table->char('fact_fingerprint', 64);
            // The sentences around the figures, each with who wrote it: ai | template | edited.
            $table->json('slots');
            $table->text('body_rendered');
            // Numbers a person typed into those sentences, and what was ticked when the letter was finalised.
            $table->json('typed_numbers')->nullable();
            $table->json('number_ticks')->nullable();
            $table->string('addressed_to', 200)->nullable();
            $table->string('subject', 255);
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalised_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        // The register entries a letter cites.
        Schema::create('site_letter_hindrances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_letter_id')->constrained('site_letters')->cascadeOnDelete();
            $table->foreignId('hindrance_id')->constrained('hindrances')->cascadeOnDelete();
            $table->unique(['site_letter_id', 'hindrance_id']);
            $table->index('hindrance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_letter_hindrances');
        Schema::dropIfExists('site_letters');
    }
};
