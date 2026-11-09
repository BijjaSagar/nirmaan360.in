<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('billing_cycle')->nullable();
            $table->timestamp('paid_until')->nullable();
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan');
            $table->string('cycle');
            $table->decimal('base_amount', 15, 2);
            $table->decimal('gst_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->string('status')->default('created');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('sales');
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email');
            $table->string('phone', 20)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
        Schema::dropIfExists('billing_payments');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle', 'paid_until']);
        });
    }
};
