<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['projects', 'boq_items', 'petty_cash_wallets'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('client_uuid')->nullable();
                $table->unique(['organization_id', 'client_uuid']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'client_uuid']);
            });

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('client_uuid');
            });
        }
    }
};
