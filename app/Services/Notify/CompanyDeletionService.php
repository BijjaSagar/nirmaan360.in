<?php

namespace App\Services\Notify;

use App\Mail\Notify\WorkflowNotificationMail;
use App\Models\AuditLog;
use App\Models\CompanyDeletionRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * The owner must type the company's name exactly. Asking twice returns the first request.
     *
     * @param  string  $via  'company' from the Company data page, 'account' when the only owner deletes their own account.
     *                       It is kept only on the request this call creates.
     */
    public function request(Organization $organization, User $by, string $typedName, string $via = 'company'): CompanyDeletionRequest
    {
        if (trim($typedName) !== $organization->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the company name exactly as shown.')]);
        }

        $request = DB::transaction(function () use ($organization, $by, $via) {
            Organization::whereKey($organization->id)->lockForUpdate()->first();

            if ($existing = $this->pending($organization->id)) {
                return $existing;
            }

            $request = CompanyDeletionRequest::create([
                'organization_id' => $organization->id,
                'requested_by' => $by->id,
                'requested_via' => $via,
                'status' => 'pending',
                'requested_at' => now(),
                'purge_after' => now()->addDays(self::GRACE_DAYS),
            ]);

            AuditLog::record('company.deletion_requested', $organization, null, ['requested_by' => $by->id, 'purge_after' => $request->purge_after->toIso8601String()]);

            return $request;
        });

        if ($request->wasRecentlyCreated) {
            $this->tellEveryone($organization, 'company.deletion_requested', function (string $locale, bool $leader) use ($organization, $request) {
                $when = __('All data will be erased on :date unless an owner cancels before then.', ['date' => $request->purge_after->format('d M Y')], $locale);

                return [
                    __('Company deletion requested for :company', ['company' => $organization->name], $locale),
                    // Members cannot open the Company data page, so they are told what it means for them instead.
                    $leader ? $when : $when.' '.__('Your access ends then, because all of the company\'s data will be erased.', [], $locale),
                ];
            });
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

        $this->tellEveryone($organization, 'company.deletion_cancelled', fn (string $locale) => [
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
        $confirmation = null;

        DB::transaction(function () use ($requestId, &$files, &$confirmation) {
            $request = CompanyDeletionRequest::withoutGlobalScopes()->whereKey($requestId)->lockForUpdate()->first();

            if (! $request || $request->status !== 'pending' || Carbon::parse($request->purge_after)->isFuture()) {
                return;
            }

            $organizationId = $request->organization_id;
            $files = $this->data->storedFiles($organizationId);
            $confirmation = $this->confirmationFor($request);

            $this->retainTaxRecords($organizationId);
            $this->eraseSignInTraces($organizationId);
            $this->eraseStockLedger($organizationId);

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

        $this->confirmErased($requestId, $confirmation);

        Log::info('Company purged', ['request_id' => $requestId]);

        return true;
    }

    /**
     * Read before the erase, because the requester's row goes with the company. The address lives
     * only in this array until the email is sent; nothing writes it anywhere.
     *
     * @return array{email:string, locale:string, message:NotificationMessage}|null
     */
    private function confirmationFor(CompanyDeletionRequest $request): ?array
    {
        $requester = $request->requested_by === null ? null : DB::table('users')
            ->where('organization_id', $request->organization_id)
            ->where('id', $request->requested_by)
            ->first(['email', 'locale']);
        $company = DB::table('organizations')->where('id', $request->organization_id)->value('name');

        if (! $requester || ! is_string($requester->email) || $requester->email === '' || ! is_string($company)) {
            return null;
        }

        $locale = $requester->locale ?: config('app.locale');
        $brand = config('buildos.brand');

        return [
            'email' => $requester->email,
            'locale' => $locale,
            'message' => new NotificationMessage(
                event: 'company.erased',
                title: __('Your company :company has been erased', ['company' => $company], $locale),
                body: __('All of its data has been deleted from :brand, as you asked. Records of payments to :brand are kept as Indian tax law requires.', ['brand' => $brand], $locale),
                critical: true,
            ),
        ];
    }

    /** Sent straight away, not queued, so the address is never written to the jobs table. A failure never undoes the purge. */
    private function confirmErased(int $requestId, ?array $confirmation): void
    {
        if ($confirmation === null || ! $this->notifier->connected('email')) {
            return;
        }

        try {
            Mail::to($confirmation['email'])->locale($confirmation['locale'])->send(new WorkflowNotificationMail($confirmation['message']));
        } catch (Throwable $exception) {
            Log::warning('The erasure confirmation email could not be sent', [
                'request_id' => $requestId,
                'error' => Str::limit(str_ireplace($confirmation['email'], '[hidden]', $exception->getMessage()), 240),
            ]);
        }
    }

    /**
     * stock_ledger refuses every DELETE (see 2026_12_05_100002) and its organisation key has no cascade,
     * so a company's movements are removed here, under a marker the database checks for this company only.
     */
    private function eraseStockLedger(int $organizationId): void
    {
        DB::table('stock_ledger_purges')->insert(['purging_organization_id' => $organizationId, 'created_at' => now()]);
        DB::table('stock_ledger')->where('organization_id', $organizationId)->delete();
        DB::table('stock_ledger_purges')->where('purging_organization_id', $organizationId)->delete();
    }

    /** GST law requires us to keep records of what we sold, so paid subscriptions and AI credit packs outlive the company. */
    private function retainTaxRecords(int $organizationId): void
    {
        $company = DB::table('organizations')->where('id', $organizationId)->first(['name', 'gstin']);

        foreach (DB::table('billing_payments')->where('organization_id', $organizationId)->where('status', 'paid')->orderBy('id')->get() as $payment) {
            $this->retain($company, 'subscription', $payment, $payment->plan, $payment->cycle);
        }

        // A paid top-up is marked by CreditService::markPaid.
        foreach (DB::table('credit_topups')->where('organization_id', $organizationId)->where('status', 'paid')->orderBy('id')->get() as $topup) {
            $this->retain($company, 'ai_credits', $topup, $topup->pack, 'one_off');
        }
    }

    private function retain(object $company, string $source, object $sale, string $plan, string $cycle): void
    {
        DB::table('retained_billing_records')->insert([
            'source' => $source,
            'original_payment_id' => $sale->id,
            'company_name' => $company->name,
            'gstin' => $company->gstin,
            'plan' => $plan,
            'cycle' => $cycle,
            'base_amount' => $sale->base_amount,
            'gst_amount' => $sale->gst_amount,
            'total_amount' => $sale->total_amount,
            'currency' => $sale->currency,
            'gateway_order_id' => $sale->gateway_order_id,
            'gateway_payment_id' => $sale->gateway_payment_id,
            'paid_at' => $sale->paid_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /**
     * Everyone loses access when the company is erased, so every active person is told, contractor logins too.
     * Only owners and admins can open the Company data page, so only they get the link.
     *
     * @param  callable(string, bool):array{0:string,1:string}  $words  Title and body in one person's language; the flag says
     *                                                                  whether they are an owner or admin.
     */
    private function tellEveryone(Organization $organization, string $event, callable $words): void
    {
        $people = User::where('organization_id', $organization->id)->where('is_active', true)->orderBy('id')->get();

        foreach ($people as $user) {
            $leader = in_array($user->role, ['owner', 'admin'], true);
            [$title, $body] = $words($user->locale ?: config('app.locale'), $leader);

            $this->notifier->send($user, new NotificationMessage(event: $event, title: $title, body: $body, url: $leader ? route('notify.data.index') : null, critical: true));
        }
    }
}
