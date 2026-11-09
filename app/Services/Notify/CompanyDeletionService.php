<?php

namespace App\Services\Notify;

use App\Models\AuditLog;
use App\Models\CompanyDeletionRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** An owner asks, the company keeps working through a grace period, then a scheduled job erases it. */
class CompanyDeletionService
{
    public const GRACE_DAYS = 30;

    public function __construct(private TenantData $data, private Notifier $notifier) {}

    public function pending(int $organizationId): ?CompanyDeletionRequest
    {
        return CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $organizationId)->where('status', 'pending')->first();
    }

    /** The owner must type the company's name exactly. Asking twice returns the first request. */
    public function request(Organization $organization, User $by, string $typedName): CompanyDeletionRequest
    {
        if (trim($typedName) !== $organization->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the company name exactly as shown.')]);
        }

        $request = DB::transaction(function () use ($organization, $by) {
            Organization::whereKey($organization->id)->lockForUpdate()->first();

            if ($existing = $this->pending($organization->id)) {
                return $existing;
            }

            $request = CompanyDeletionRequest::create([
                'organization_id' => $organization->id,
                'requested_by' => $by->id,
                'status' => 'pending',
                'requested_at' => now(),
                'purge_after' => now()->addDays(self::GRACE_DAYS),
            ]);

            AuditLog::record('company.deletion_requested', $organization, null, ['requested_by' => $by->id, 'purge_after' => $request->purge_after->toIso8601String()]);

            return $request;
        });

        if ($request->wasRecentlyCreated) {
            $this->tellLeaders($organization, 'company.deletion_requested', fn (string $locale) => [
                __('Company deletion requested for :company', ['company' => $organization->name], $locale),
                __('All data will be erased on :date unless an owner cancels before then.', ['date' => $request->purge_after->format('d M Y')], $locale),
            ]);
        }

        return $request;
    }

    public function cancel(Organization $organization, User $by): void
    {
        $request = $this->pending($organization->id);

        if (! $request) {
            return;
        }

        $request->update(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now()]);
        AuditLog::record('company.deletion_cancelled', $organization, null, ['cancelled_by' => $by->id]);

        $this->tellLeaders($organization, 'company.deletion_cancelled', fn (string $locale) => [
            __('Company deletion cancelled for :company', ['company' => $organization->name], $locale),
            __('Your data is safe and will not be erased.', [], $locale),
        ]);
    }

    /** One company's failure is logged and does not stop the others. */
    public function purgeDue(): int
    {
        $purged = 0;

        $due = CompanyDeletionRequest::withoutGlobalScopes()->where('status', 'pending')->where('purge_after', '<=', now())->pluck('id');

        foreach ($due as $id) {
            try {
                $purged += (int) $this->purge($id);
            } catch (Throwable $exception) {
                Log::error('Company purge failed', ['request_id' => $id, 'error' => $exception->getMessage()]);
            }
        }

        return $purged;
    }

    /** Irreversible. Rechecks the request under a lock, so a late cancel always wins. */
    private function purge(int $requestId): bool
    {
        $files = null;

        DB::transaction(function () use ($requestId, &$files) {
            $request = CompanyDeletionRequest::withoutGlobalScopes()->whereKey($requestId)->lockForUpdate()->first();

            if (! $request || $request->status !== 'pending' || Carbon::parse($request->purge_after)->isFuture()) {
                return;
            }

            $organizationId = $request->organization_id;
            $files = $this->data->storedFiles($organizationId);

            $this->eraseSignInTraces($organizationId);

            // Foreign keys remove most rows. The sweep catches tables other modules made without one.
            Organization::whereKey($organizationId)->delete();

            foreach (array_keys($this->data->tables()) as $table) {
                if ($table !== 'company_deletion_requests') {
                    DB::table($table)->where('organization_id', $organizationId)->delete();
                }
            }

            $request->update(['status' => 'completed', 'completed_at' => now(), 'requested_by' => null, 'cancelled_by' => null]);
        });

        if ($files === null) {
            return false;
        }

        // Files go last, so a failed database delete leaves the company whole.
        foreach ($files as $path) {
            try {
                Storage::delete($path);
            } catch (Throwable $exception) {
                Log::warning('A file from a deleted company could not be removed', ['path' => $path, 'error' => $exception->getMessage()]);
            }
        }

        Log::info('Company purged', ['request_id' => $requestId]);

        return true;
    }

    /** These tables point at a user or a phone number and carry no organization_id. */
    private function eraseSignInTraces(int $organizationId): void
    {
        $users = DB::table('users')->where('organization_id', $organizationId)->get(['id', 'email', 'phone']);
        $ids = $users->pluck('id');

        DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $ids)->delete();
        $this->data->notificationsQuery($organizationId)->delete();
        DB::table('sessions')->whereIn('user_id', $ids)->delete();
        DB::table('password_reset_tokens')->whereIn('email', $users->pluck('email'))->delete();
        DB::table('otp_codes')->whereIn('phone', $users->pluck('phone')->filter())->delete();
    }

    /** @param callable(string):array{0:string,1:string} $words Title and body in one person's language. */
    private function tellLeaders(Organization $organization, string $event, callable $words): void
    {
        $leaders = User::where('organization_id', $organization->id)->where('is_active', true)->whereIn('role', ['owner', 'admin'])->get();

        foreach ($leaders as $user) {
            [$title, $body] = $words($user->locale ?: config('app.locale'));

            $this->notifier->send($user, new NotificationMessage(event: $event, title: $title, body: $body, url: route('notify.data.index'), critical: true));
        }
    }
}
