<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a person typed from the agreement, with the date it applies from and where it is written.
        // Nothing here has a default and nothing is seeded: a term counts only once someone has confirmed it.
        Schema::create('contract_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // No foreign key. Null means the contract with the client.
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->string('kind', 20);
            $table->string('code', 40);
            $table->string('label', 160);
            $table->string('method', 20)->nullable();
            $table->decimal('pct', 7, 4)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('base', 16)->nullable();
            $table->decimal('cap_amount', 15, 2)->nullable();
            $table->string('nature', 24)->nullable();
            $table->unsignedInteger('days')->nullable();
            $table->string('counted_from', 24)->nullable();
            $table->string('combine', 10)->nullable();
            $table->string('combine_group', 20)->nullable();
            $table->string('value_text', 255)->nullable();
            $table->date('value_date')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // The clause or page, as the person typed it. No copy of the agreement is kept.
            $table->string('source_ref', 255)->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'work_order_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_terms');
    }
};
