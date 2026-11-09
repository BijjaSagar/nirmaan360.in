<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The subcontractor a contractor login belongs to. A plain id with no foreign key, so modules can merge in any order.
            $table->unsignedBigInteger('contractor_party_id')->nullable();
            $table->index('contractor_party_id');
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            // Set on an invitation for a contractor login, and copied to the user when the link is accepted.
            $table->unsignedBigInteger('contractor_party_id')->nullable();
        });
    }

    public function down(): void
    {
        // SQLite cannot drop a column that an index still uses, so the index goes first.
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['contractor_party_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contractor_party_id');
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropColumn('contractor_party_id');
        });
    }
};
