<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a person deleted their own account. The row stays, so every record they made keeps its author,
        // but their name, email and phone are gone. Deliberately not SoftDeletes: its global scope would hide
        // the row from every relation and show their work as made by nobody.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymised_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymised_at');
        });
    }
};
