<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What was sold: a plan ('subscription', from billing_payments) or AI credits ('ai_credits', from credit_topups).
        // original_payment_id points into the table the source names.
        Schema::table('retained_billing_records', function (Blueprint $table) {
            $table->string('source', 20)->default('subscription');
        });
    }

    public function down(): void
    {
        Schema::table('retained_billing_records', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
