<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UUID_TABLES = ['attendance', 'workers', 'dpr_photos', 'approval_steps'];

    private const STAMPED_TABLES = ['dprs', 'issues', 'attendance', 'measurement_entries'];

    public function up(): void
    {
        foreach (self::UUID_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('client_uuid')->nullable();
                $table->unique(['organization_id', 'client_uuid']);
            });
        }

        foreach (self::STAMPED_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('client_updated_at')->nullable();
            });
        }

        Schema::table('dpr_photos', function (Blueprint $table) {
            $table->timestamp('taken_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dpr_photos', function (Blueprint $table) {
            $table->dropColumn('taken_at');
        });

        foreach (self::STAMPED_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('client_updated_at');
            });
        }

        foreach (self::UUID_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'client_uuid']);
            });

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('client_uuid');
            });
        }
    }
};
