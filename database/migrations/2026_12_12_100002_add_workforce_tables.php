<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raised to cancel a worker's QR card: a card made for an older version stops scanning.
        Schema::table('workers', function (Blueprint $table) {
            $table->unsignedInteger('qr_version')->default(1);
        });

        Schema::create('labour_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained();
            $table->foreignId('boq_item_id')->constrained();
            $table->date('work_date');
            $table->decimal('man_days', 14, 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'client_uuid']);
            $table->index(['project_id', 'work_date']);
            $table->index(['project_id', 'boq_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_allocations');

        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('qr_version');
        });
    }
};
