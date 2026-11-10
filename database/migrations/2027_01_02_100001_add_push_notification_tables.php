<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('app_version', 32)->nullable();
            // The language the app is shown in on this phone, so its pushes are written in it.
            $table->string('locale', 8)->nullable();
            // The sign-in that registered the phone. Once that sign-in ends, the phone gets nothing more. No foreign key: Sanctum owns that table.
            $table->unsignedBigInteger('access_token_id')->nullable();
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
            // Category name => false for each kind of push the person has switched off. A missing name means on.
            $table->json('push_categories')->nullable();
            $table->boolean('quiet_hours')->default(true);
            // "HH:MM" on the Asia/Kolkata clock. Null means the default in config/modules/notify.php.
            $table->string('quiet_from', 5)->nullable();
            $table->string('quiet_until', 5)->nullable();
        });

        // One row per person and thing pushed, so a scan that runs again does not push it a second time.
        Schema::create('push_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 190);
            $table->string('event', 64);
            $table->timestamp('claimed_at');
            $table->unique(['user_id', 'dedupe_key']);
            $table->index('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_receipts');

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['push_categories', 'quiet_hours', 'quiet_from', 'quiet_until']);
        });

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'locale', 'access_token_id']);
        });
    }
};
