<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tax records of paid subscriptions, kept after a company is erased. No organization_id, on purpose. */
    public function up(): void
    {
        Schema::create('retained_billing_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_payment_id');
            $table->string('company_name');
            $table->string('gstin', 20)->nullable();
            $table->string('plan', 40);
            $table->string('cycle', 20);
            $table->decimal('base_amount', 15, 2);
            $table->decimal('gst_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->string('currency', 8)->default('INR');
            $table->string('gateway_order_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retained_billing_records');
    }
};
