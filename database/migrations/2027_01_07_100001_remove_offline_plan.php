<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The offline plan is gone: every company syncs online.
 *
 * Any company still on plan 'offline' moves to the free plan, active, with no trial, paid period or billing cycle,
 * and each move is written to that company's audit log as platform.plan_changed with no names, emails or company
 * name in it. Then the device licence table is dropped, and the plan's saved prices and phone limit are taken out
 * of the admin's stored plan settings. With no company on the plan, the moves and the audit rows are simply skipped.
 *
 * Plain queries rather than models, so this keeps working whatever the models become.
 */
return new class extends Migration
{
    private const PLAN = 'offline';

    private const FEATURE = 'offline_plan';

    public function up(): void
    {
        $now = now();
        $licences = Schema::hasTable('device_licences');

        $companies = DB::table('organizations')
            ->where('plan', self::PLAN)
            ->orderBy('id')
            ->get(['id', 'plan', 'plan_status', 'trial_ends_at', 'paid_until', 'billing_cycle']);

        foreach ($companies as $company) {
            $new = ['plan' => 'free', 'plan_status' => 'active', 'trial_ends_at' => null, 'paid_until' => null, 'billing_cycle' => null];

            DB::table('organizations')->where('id', $company->id)->update([...$new, 'updated_at' => $now]);

            DB::table('audit_logs')->insert([
                'organization_id' => $company->id,
                'user_id' => null,
                'action' => 'platform.plan_changed',
                'auditable_type' => 'App\\Models\\Organization',
                'auditable_id' => $company->id,
                'old_values' => json_encode([
                    'plan' => $company->plan,
                    'plan_status' => $company->plan_status,
                    'trial_ends_at' => $company->trial_ends_at,
                    'paid_until' => $company->paid_until,
                    'billing_cycle' => $company->billing_cycle,
                ]),
                'new_values' => json_encode([
                    ...$new,
                    'reason' => 'offline_plan_removed',
                    'device_licences_removed' => $licences ? DB::table('device_licences')->where('organization_id', $company->id)->count() : 0,
                ]),
                'ip_address' => null,
                'created_at' => $now,
            ]);
        }

        Schema::dropIfExists('device_licences');

        $this->forgetSavedPlanSettings();
    }

    /**
     * Only the table comes back, empty and with the schema it had. The companies moved to the free plan stay on it,
     * and the licence rows dropped in up() do not come back: nothing kept them.
     */
    public function down(): void
    {
        if (Schema::hasTable('device_licences')) {
            return;
        }

        Schema::create('device_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 64);
            $table->string('device_name', 120);
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('slot')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'device_id']);
            $table->unique(['organization_id', 'slot']);
        });
    }

    /**
     * The admin's saved plan settings (see PlatformSettings) may still hold the plan's prices and phone limit, and a
     * plan may have had the plan's own feature ticked. Both would only ever be ignored now; they go so nothing stale
     * is left. Anything that does not read as the expected JSON is left as it is.
     */
    private function forgetSavedPlanSettings(): void
    {
        $raw = DB::table('platform_settings')->where('key', 'plans')->value('value');
        $plans = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($plans)) {
            return;
        }

        $cleaned = array_diff_key($plans, [self::PLAN => true]);

        foreach ($cleaned as $key => $plan) {
            if (is_array($plan) && is_array($plan['features'] ?? null)) {
                $cleaned[$key]['features'] = array_values(array_filter($plan['features'], fn ($feature) => $feature !== self::FEATURE));
            }
        }

        if ($cleaned === $plans) {
            return;
        }

        DB::table('platform_settings')->where('key', 'plans')->update(['value' => json_encode($cleaned), 'updated_at' => now()]);

        // PlatformSettings keeps every setting in the cache for good, so the old copy has to go too.
        try {
            Cache::forget('platform_settings.v1');
        } catch (Throwable) {
            // A cache that cannot be reached holds nothing this could clear. The next save in /admin clears it anyway.
        }
    }
};
