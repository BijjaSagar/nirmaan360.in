<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A payment that takes a contract ahead of the work done, past the project's limit, is approved only by
        // the owner with a written reason. The reason, who gave it and when stay with the payment.
        Schema::table('contractor_payments', function (Blueprint $table) {
            $table->string('override_reason', 1000)->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('override_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contractor_payments', function (Blueprint $table) {
            $table->dropForeign(['override_by']);
        });

        Schema::table('contractor_payments', function (Blueprint $table) {
            $table->dropColumn(['override_reason', 'override_by', 'override_at']);
        });
    }
};
