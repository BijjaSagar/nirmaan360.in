<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One request to have the AI group the hindrance notes of daily reports. It keeps which reports were sent,
        // in the order the AI was given them, so its answer is read against those reports and no others. It holds
        // no word of what was sent or of what came back: the entries it led to are in the register as proposed.
        Schema::create('hindrance_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The ids of the daily reports, in order: the first is D1 to the AI, the second D2, and so on.
            $table->json('dpr_ids');
            // The dates asked for, when the person narrowed them.
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            // waiting | done | failed
            $table->string('status', 12);
            // Entries put forward, and parts of the answer that could not be tied to a report and were left out.
            $table->unsignedInteger('proposed')->default(0);
            $table->unsignedInteger('left_out')->default(0);
            $table->string('fail_reason', 24)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hindrance_suggestions');
    }
};
