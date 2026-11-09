<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // What kind of alert, for example schedule.behind, and what it is about inside the project.
            $table->string('type', 60);
            $table->string('reference', 120)->default('project');
            $table->string('severity', 12)->default('warning');
            // English wording, translated when shown. params fill the :placeholders; data keeps the figures behind them.
            $table->string('title', 160);
            $table->text('message');
            $table->json('params')->nullable();
            $table->json('data')->nullable();
            // Null means everyone with alerts.view on the project. money.view or money.overview hides it from site staff.
            $table->string('needs_permission', 60)->nullable();
            // The permission of the people told when the alert opens.
            $table->string('notify_permission', 60)->nullable();
            $table->string('url')->nullable();
            $table->string('status', 12)->default('open');
            // "type|reference" while open and null once resolved, so the database itself refuses a second open alert for one cause.
            $table->string('open_key', 200)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'open_key']);
            $table->index(['organization_id', 'status']);
            $table->index(['project_id', 'status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
