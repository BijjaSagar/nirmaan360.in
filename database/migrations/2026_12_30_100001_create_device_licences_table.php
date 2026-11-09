<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Who activated the phone. The licence belongs to the company, not the person.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The app's own stable id for the phone. Never a phone number or an IMEI.
            $table->string('device_id', 64);
            $table->string('device_name', 120);
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('status', 16)->default('active');
            // Every active licence holds one numbered slot. The unique key below means two phones can
            // never both take the last slot, whatever the database and whether or not rows were locked.
            $table->unsignedSmallInteger('slot')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('last_seen_at')->nullable();
            // When the signed token the phone holds runs out. The phone refreshes it whenever it is online.
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'device_id']);
            $table->unique(['organization_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_licences');
    }
};
