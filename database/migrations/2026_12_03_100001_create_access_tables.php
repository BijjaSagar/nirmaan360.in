<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // The approval level this role acts at, if any. Never owner or admin.
            $table->string('approval_role', 32)->nullable();
            $table->json('permissions');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('phone', 20)->nullable();
            // A built-in role name, or custom:<id>.
            $table->string('role_key', 64);
            $table->boolean('limited_to_projects')->default(false);
            $table->json('project_ids')->nullable();
            // Only the SHA-256 of the link's token is kept.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        Schema::create('user_project_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
            $table->index('project_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // No delete action: a role in use must not vanish and leave the person on their base role.
            $table->foreignId('custom_role_id')->nullable()->constrained('custom_roles');
            // A separate flag, so that losing the last project means no access rather than full access.
            $table->boolean('limited_to_projects')->default(false);
            // When role or projects last changed, so the field app can fetch what it was not sent before.
            $table->timestamp('access_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_role_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['limited_to_projects', 'access_changed_at']);
        });

        Schema::dropIfExists('user_project_access');
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('custom_roles');
    }
};
