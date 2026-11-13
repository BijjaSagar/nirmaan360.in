<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The field app adds floors and segments. A repeated request with the same client_uuid returns the floor it made.
    public function up(): void
    {
        Schema::table('project_floors', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable();
            $table->unique(['organization_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('project_floors', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
