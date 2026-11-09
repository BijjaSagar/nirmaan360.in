<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('site_texts', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5);
            $table->string('key_hash', 40);
            $table->text('source');
            $table->text('value');
            $table->timestamps();
            $table->unique(['locale', 'key_hash']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });

        Schema::dropIfExists('site_texts');
        Schema::dropIfExists('platform_settings');
    }
};
