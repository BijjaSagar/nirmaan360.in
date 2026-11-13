<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cost entry is never deleted. A mistake is cancelled by a second entry with the opposite amount,
        // linked here, so the original stays on record and the two net to zero. Unique: one reversal per entry.
        Schema::table('plan_cost_entries', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('plan_cost_entries')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plan_cost_entries', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->dropUnique(['reversal_of_id']);
        });

        Schema::table('plan_cost_entries', function (Blueprint $table) {
            $table->dropColumn(['reversal_of_id', 'reversal_reason']);
        });
    }
};
