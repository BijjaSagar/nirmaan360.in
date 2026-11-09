<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A flat sits under a floor. Two floors may each have a "Passage", so the name is no longer
        // unique across the whole project; the service keeps it unique among the same parent.
        Schema::table('project_floors', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'name']);
            $table->unsignedBigInteger('parent_id')->nullable()->index();
        });

        // Entries are never edited. A correction or the closing of a pending remark is a new entry that names the one it answers.
        Schema::table('progress_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('corrects_entry_id')->nullable()->index();
            $table->unsignedBigInteger('resolves_entry_id')->nullable()->index();
        });

        // How much of a BOQ line belongs to a floor. Floor percentages need it, because the BOQ states quantities for the whole project.
        Schema::create('floor_work_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_floor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('planned_qty', 14, 3);
            $table->timestamps();
            $table->unique(['project_floor_id', 'boq_item_id']);
            $table->index(['project_id', 'boq_item_id']);
        });

        // Current state of one plumbing or electrical stage on one floor or flat.
        Schema::create('mep_stage_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_floor_id')->constrained()->cascadeOnDelete();
            $table->string('trade', 16);
            $table->string('stage', 32);
            $table->string('status', 16)->default('not_started');
            $table->text('remark')->nullable();
            $table->timestamp('status_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_floor_id', 'trade', 'stage']);
            $table->index(['project_id', 'status']);
        });

        // Every status change, so the cell above can be traced back and a repeated upload from a phone changes nothing.
        Schema::create('mep_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mep_stage_cell_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('status', 16);
            $table->text('remark')->nullable();
            // Set when the stage was marked done while an earlier stage on the same row was pending.
            $table->string('out_of_order_after', 32)->nullable();
            // False when the phone's change is older than what the cell already holds.
            $table->boolean('applied')->default(true);
            $table->timestamp('occurred_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
        });

        // Private photos on a progress entry or on a stage cell.
        Schema::create('progress_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('progress_entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('mep_stage_cell_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_photos');
        Schema::dropIfExists('mep_stage_events');
        Schema::dropIfExists('mep_stage_cells');
        Schema::dropIfExists('floor_work_plans');

        Schema::table('progress_entries', function (Blueprint $table) {
            $table->dropIndex(['corrects_entry_id']);
            $table->dropIndex(['resolves_entry_id']);
            $table->dropColumn(['corrects_entry_id', 'resolves_entry_id']);
        });

        // Names that were only unique under their parent would stop the old unique key from being rebuilt.
        $seen = [];

        foreach (DB::table('project_floors')->orderBy('id')->get(['id', 'project_id', 'name']) as $floor) {
            $key = $floor->project_id.'|'.mb_strtolower($floor->name);

            if (isset($seen[$key])) {
                DB::table('project_floors')->where('id', $floor->id)->update(['name' => mb_substr($floor->name, 0, 70).' #'.$floor->id]);
            }

            $seen[$key] = true;
        }

        Schema::table('project_floors', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropColumn('parent_id');
            $table->unique(['project_id', 'name']);
        });
    }
};
