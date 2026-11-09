<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ncrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('seq');
            $table->string('number', 40);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('location', 160)->nullable();
            $table->string('category', 30)->default('workmanship');
            $table->string('severity', 20)->default('minor');
            $table->date('raised_on');
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('root_cause_category', 30)->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('preventive_action')->nullable();
            $table->decimal('rework_cost', 15, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'seq']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('quality_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('test_type', 20)->default('cube');
            $table->string('sample_ref', 60)->nullable();
            $table->string('element', 160);
            $table->string('grade', 20)->nullable();
            // N/mm2. Read from the grade (M25 is 25) when it is not entered.
            $table->decimal('required_strength', 8, 2)->nullable();
            $table->date('cast_date');
            $table->decimal('quantity_cum', 14, 3)->nullable();
            $table->string('supplier', 120)->nullable();
            $table->string('cast_by', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'cast_date']);
        });

        Schema::create('quality_test_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_test_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('specimen_no');
            $table->unsignedSmallInteger('age_days');
            $table->date('due_on');
            $table->date('tested_on')->nullable();
            $table->decimal('load_kn', 10, 2)->nullable();
            $table->decimal('strength', 8, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['quality_test_id', 'age_days']);
            $table->index(['organization_id', 'due_on']);
        });

        Schema::create('safety_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('seq');
            $table->string('number', 40);
            $table->string('kind', 20)->default('incident');
            $table->string('severity', 20)->default('low');
            $table->string('injury', 20)->default('none');
            $table->unsignedSmallInteger('lost_days')->default(0);
            $table->timestamp('occurred_at');
            $table->string('location', 160)->nullable();
            $table->text('description');
            $table->text('persons')->nullable();
            $table->text('immediate_action')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'seq']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('toolbox_talks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->date('talk_date');
            $table->string('topic', 160);
            $table->string('conducted_by', 80);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->text('attendees')->nullable();
            $table->unsignedInteger('attendee_count')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'talk_date']);
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category', 30)->default('general');
            $table->string('description')->nullable();
            $table->json('items');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'client_uuid']);
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checklist_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->string('category', 30)->default('general');
            $table->string('location', 160)->nullable();
            $table->date('inspected_on');
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'category']);
        });

        Schema::create('inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort');
            $table->string('text');
            $table->string('result', 10)->default('pending');
            $table->string('remark')->nullable();
            $table->timestamps();
            $table->index(['inspection_id', 'sort']);
        });

        Schema::create('rfis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('number', 40);
            $table->string('subject', 160);
            $table->text('question');
            $table->string('drawing_ref', 120)->nullable();
            $table->string('location', 160)->nullable();
            $table->string('addressed_to', 120)->nullable();
            $table->string('priority', 20)->default('medium');
            $table->date('required_by')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response')->nullable();
            $table->date('responded_on')->nullable();
            $table->string('responded_by', 120)->nullable();
            $table->boolean('cost_impact')->default(false);
            $table->unsignedSmallInteger('time_impact_days')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'seq']);
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'status']);
        });

        // Photos and documents on NCRs, incidents, RFIs and quality tests. Stored privately.
        Schema::create('field_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 80)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('caption')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_attachments');
        Schema::dropIfExists('rfis');
        Schema::dropIfExists('inspection_items');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('toolbox_talks');
        Schema::dropIfExists('safety_incidents');
        Schema::dropIfExists('quality_test_results');
        Schema::dropIfExists('quality_tests');
        Schema::dropIfExists('ncrs');
    }
};
