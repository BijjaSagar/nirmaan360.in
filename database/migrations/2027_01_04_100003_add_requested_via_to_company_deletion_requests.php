<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'company' from the Company data page, 'account' when the only owner deleted their own account.
        Schema::table('company_deletion_requests', function (Blueprint $table) {
            $table->string('requested_via', 20)->default('company');
        });
    }

    public function down(): void
    {
        Schema::table('company_deletion_requests', function (Blueprint $table) {
            $table->dropColumn('requested_via');
        });
    }
};
