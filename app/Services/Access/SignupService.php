<?php

namespace App\Services\Access;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Starting a company with its first owner, from the web form, the field app or the console.
 *
 * Emails are stored trimmed and in lower case and compared in any case. The unique index alone is
 * case-sensitive, while a platform admin is recognised by email in any case, so without this a
 * stranger could take a listed admin email by typing it in capitals.
 */
class SignupService
{
    /** The plan a company started from the website or the console gets when none is chosen. */
    public const DEFAULT_PLAN = 'contractor';

    /** The only plan a company started from the field app gets. See appPlan(). */
    public const APP_PLAN = 'free';

    /**
     * Plans a company can start by itself: anything with a price, free included.
     * Enterprise has none and is priced by conversation.
     *
     * @return list<string>
     */
    public function selfServePlans(): array
    {
        return array_keys(array_filter(config('buildos.plans'), fn (array $plan) => $plan['monthly'] !== null));
    }

    /** Falls back to free if an admin has taken the default plan off sale. */
    public function defaultPlan(): string
    {
        return in_array(self::DEFAULT_PLAN, $this->selfServePlans(), true) ? self::DEFAULT_PLAN : 'free';
    }

    /**
     * The plan for a company started from the field app: free, active and with no trial, so nothing it was
     * given in the app ever needs paying for outside the app (App Store guideline 3.1.1). Null when the free
     * plan has been renamed, taken off sale or given a price: the app's sign-up is then refused rather than
     * started on a paid plan or a trial.
     */
    public function appPlan(): ?string
    {
        $plan = config('buildos.plans.'.self::APP_PLAN);

        return is_array($plan) && ($plan['monthly'] ?? null) === 0 && ($plan['yearly'] ?? null) === 0 ? self::APP_PLAN : null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'company' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                if ($message = $this->emailProblem(User::normaliseEmail($value))) {
                    $fail($message);
                }
            }],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.unique' => __('This mobile number already has an account.')];
    }

    /**
     * The website and the console start the company on the plan given, or the default plan, with a trial
     * unless it is free. The field app always starts it on the free plan with no trial, whatever plan it sent.
     *
     * @param  array{company: string, name: string, email: string, phone?: ?string, password: string, plan?: ?string, locale?: ?string}  $data
     * @param  'web'|'app'|'console'  $method
     *
     * @throws ValidationException when the email or mobile number was taken after validation ran,
     *                             or the app signs up while there is no free plan to start on
     */
    public function create(array $data, string $method): User
    {
        $plan = $method === 'app' ? $this->appPlan() : ($data['plan'] ?? $this->defaultPlan());

        if ($plan === null) {
            throw ValidationException::withMessages(['company' => __('A new company cannot be started from the app right now. Try again later.')]);
        }

        $isFree = $method === 'app' || $plan === 'free';
        $email = User::normaliseEmail($data['email']);

        try {
            return DB::transaction(function () use ($data, $method, $plan, $isFree, $email) {
                // Checked again here: another sign-up with this email may have finished since validation.
                if ($message = $this->emailProblem($email)) {
                    throw ValidationException::withMessages(['email' => $message]);
                }

                $organization = Organization::create([
                    'name' => $data['company'],
                    'slug' => Str::slug($data['company']).'-'.Str::lower(Str::random(6)),
                    'plan' => $plan,
                    'plan_status' => $isFree ? 'active' : 'trial',
                    'trial_ends_at' => $isFree ? null : now()->addDays(config('buildos.trial_days')),
                ]);
                $organization->seedDefaults();

                $user = User::create([
                    'organization_id' => $organization->id,
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $data['phone'] ?? null,
                    'role' => 'owner',
                    'locale' => $this->locale($data['locale'] ?? null),
                    'password' => $data['password'],
                ]);

                // How and on which plan only: never the name, email or company.
                AuditLog::record('account.signed_up', $user, null, ['method' => $method, 'plan' => $plan], actor: $user);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two sign-ups that passed the check at the same moment: the unique index refused the second.
            $field = $this->clashingField($exception) ?? throw $exception;

            throw ValidationException::withMessages([$field => $field === 'phone'
                ? __('This mobile number already has an account.')
                : __('This email already has an account.')]);
        }
    }

    public function welcomeMessage(Organization $organization): string
    {
        return $organization->plan === 'free'
            ? __('Welcome! Your free plan is ready.')
            : __('Welcome! Your :days-day trial has started.', ['days' => config('buildos.trial_days')]);
    }

    /**
     * Why this normalised email cannot start a company, or null when it can.
     * Platform staff are recognised by email alone, so a listed address is never handed to a new company.
     * It reads exactly like a taken email, so the answer never tells a stranger which addresses run the platform.
     * A .invalid address is what a deleted account is given, so it is refused the same way.
     */
    private function emailProblem(string $email): ?string
    {
        if (User::emailTaken($email) || User::emailReservedForPlatform($email) || User::emailOnReservedDomain($email)) {
            return __('This email already has an account.');
        }

        return null;
    }

    /** The visitor's language when none was sent, and English for anything the app does not offer. */
    private function locale(?string $wanted): string
    {
        $locale = $wanted ?? app()->getLocale();

        return array_key_exists($locale, config('buildos.locales')) ? $locale : 'en';
    }

    /**
     * Which of the users table's unique indexes refused the row.
     * Read from the driver's first line only: Postgres repeats the clashing value on the line after.
     */
    private function clashingField(UniqueConstraintViolationException $exception): ?string
    {
        $message = (string) strtok((string) ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()), "\n");

        return match (true) {
            preg_match('/users_phone_unique|users\.phone\b/', $message) === 1 => 'phone',
            preg_match('/users_email_unique|users\.email\b/', $message) === 1 => 'email',
            default => null,
        };
    }
}
