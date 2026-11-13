<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One thing that held the work up, as a person entered it from the daily reports.
        Schema::create('hindrances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40);
            $table->text('description');
            $table->date('started_on');
            // Null while the hindrance is still going on.
            $table->date('ended_on')->nullable();
            $table->string('responsible', 160)->nullable();
            // proposed | confirmed | dismissed
            $table->string('status', 12);
            // person | code | ai
            $table->string('proposed_by', 8);
            // Set once a finalised letter cites the entry. From then on it is corrected by a new entry.
            $table->timestamp('locked_at')->nullable();
            // The locked entry this one corrects. No foreign key: both rows belong to the same register.
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
            $table->index('supersedes_id');
        });

        // The daily reports an entry rests on, with the words of each report as they stood when it was cited.
        Schema::create('hindrance_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hindrance_id')->constrained('hindrances')->cascadeOnDelete();
            // No foreign key: the copy below stays even when the report itself is removed.
            $table->unsignedBigInteger('dpr_id');
            $table->date('report_date');
            $table->text('excerpt');
            $table->string('dpr_status', 16);
            $table->timestamps();
            $table->index(['hindrance_id', 'report_date']);
            $table->index(['project_id', 'dpr_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hindrance_sources');
        Schema::dropIfExists('hindrances');
    }
};
