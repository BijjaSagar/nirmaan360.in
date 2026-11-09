<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The token is encrypted, so this hash is what makes a device unique.
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->string('platform', 16);
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('email')->default(true);
            $table->boolean('push')->default(true);
            $table->boolean('whatsapp')->default(false);
            $table->timestamp('whatsapp_opted_in_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->string('event', 64);
            $table->string('error');
            $table->timestamp('created_at')->nullable();
            $table->index(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_failures');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('device_tokens');
    }
};
