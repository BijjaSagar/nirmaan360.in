<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When true, advance_recovery_pct is worked out from the advance and the contract value.
        // Off for every project that exists, so no saved percentage changes by itself.
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('advance_recovery_auto')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('advance_recovery_auto');
        });
    }
};
