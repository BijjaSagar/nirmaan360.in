<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_outcome_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // won, lost, no_bid
            $table->string('label', 120);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'kind']);
        });

        Schema::create('crm_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 16); // lead, company, tender
            $table->string('key', 40);
            $table->string('label', 80);
            $table->string('type', 10)->default('text'); // text, number, date, select
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'entity', 'key']);
        });

        // Companies are Party rows. This sidecar holds what the CRM adds to them.
        Schema::create('crm_company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->unique('party_id');
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('company_name', 160)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 24)->default('other');
            $table->string('status', 16)->default('new');
            $table->decimal('expected_value', 15, 2)->default(0);
            $table->string('location', 160)->nullable();
            $table->text('notes')->nullable();
            $table->text('lost_note')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crm_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 120)->nullable();
            $table->string('title', 200);
            $table->string('authority', 160);
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->string('location', 160)->nullable();
            $table->string('state', 60)->nullable();
            $table->string('portal_url', 500)->nullable();
            $table->string('stage', 12)->default('enquiry');
            $table->timestamp('stage_changed_at')->nullable();
            $table->decimal('estimated_value', 15, 2)->default(0);
            $table->decimal('bid_value', 15, 2)->nullable();
            $table->decimal('awarded_value', 15, 2)->nullable();
            $table->date('published_on')->nullable();
            $table->dateTime('pre_bid_at')->nullable();
            $table->string('pre_bid_note', 255)->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('opens_at')->nullable();
            $table->timestamp('due_alerted_at')->nullable();
            $table->date('closed_on')->nullable();
            $table->foreignId('outcome_reason_id')->nullable()->constrained('crm_outcome_reasons')->nullOnDelete();
            $table->text('outcome_note')->nullable();
            $table->string('winner_name', 160)->nullable();
            $table->decimal('winning_value', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'stage']);
            $table->index(['organization_id', 'due_at']);
        });

        // EMD and fees paid for a tender, with what came back.
        Schema::create('crm_tender_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('crm_tenders')->cascadeOnDelete();
            $table->string('kind', 16)->default('emd'); // emd, tender_fee, processing_fee, other
            $table->decimal('amount', 15, 2);
            $table->string('mode', 12)->default('online'); // online, dd, bg, fdr, cheque, cash, other
            $table->string('reference', 120)->nullable();
            $table->date('paid_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('refundable')->default(true);
            $table->string('status', 12)->default('paid'); // planned, paid, refunded, forfeited, adjusted
            $table->date('refund_expected_on')->nullable();
            $table->date('settled_on')->nullable();
            $table->decimal('refunded_amount', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crm_tender_boq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('crm_tenders')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('section', 120)->nullable();
            $table->string('code', 32);
            $table->text('description');
            $table->string('unit', 16);
            $table->decimal('qty', 14, 3)->default(0);
            $table->decimal('rate', 15, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['tender_id', 'sort']);
        });

        Schema::create('crm_tender_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained('crm_tenders')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('title', 160);
            $table->string('kind', 16)->default('other');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->string('subject_type', 12)->nullable(); // lead, tender, company
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->date('due_on');
            $table->string('due_time', 5)->nullable();
            $table->dateTime('remind_at')->nullable();
            $table->dateTime('reminded_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 8)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['organization_id', 'status', 'due_on']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->string('kind', 12)->default('note'); // note, call, meeting, email, whatsapp, stage, system
            $table->text('body');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('happened_at');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('crm_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('channel', 10); // whatsapp, email
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'channel']);
        });
    }

    public function down(): void
    {
        foreach ([
            'crm_templates', 'crm_activities', 'crm_tasks', 'crm_tender_documents', 'crm_tender_boq_items',
            'crm_tender_payments', 'crm_tenders', 'crm_leads', 'crm_company_profiles', 'crm_custom_fields',
            'crm_outcome_reasons',
        ] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
