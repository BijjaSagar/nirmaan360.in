<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a contractor offers besides the price, and the quotation they sent.
        Schema::table('contractor_bids', function (Blueprint $table) {
            $table->date('valid_until')->nullable();
            // Null when the quotation does not say whether material is in the rate.
            $table->boolean('includes_material')->nullable();
            $table->string('payment_terms', 500)->nullable();
            $table->unsignedInteger('completion_days')->nullable();
            $table->string('quotation_path')->nullable();
            $table->string('quotation_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contractor_bids', function (Blueprint $table) {
            $table->dropColumn(['valid_until', 'includes_material', 'payment_terms', 'completion_days', 'quotation_path', 'quotation_name']);
        });
    }
};
