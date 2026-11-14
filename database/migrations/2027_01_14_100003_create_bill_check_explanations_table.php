<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The findings of one check explained in plain words, in one language. Only the figure-free
        // sentences are kept here: the findings themselves, with every figure, are written by the code
        // from the check each time the explanation is shown.
        Schema::create('bill_check_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_check_id')->constrained('bill_checks')->cascadeOnDelete();
            $table->string('locale', 2);
            // "template" until a model's sentences passed every guard, then "ai".
            $table->string('source', 10)->default('template');
            $table->json('slots')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->string('reason', 24)->nullable();
            $table->unsignedBigInteger('ai_text_call_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('drafted_at')->nullable();
            $table->timestamps();
            $table->unique(['bill_check_id', 'locale']);
            $table->index(['organization_id', 'ai_text_call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_check_explanations');
    }
};
