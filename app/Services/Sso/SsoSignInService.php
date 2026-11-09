<?php

namespace App\Services\Sso;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Decides which existing user a Google or Microsoft identity may sign in as.
 * It never creates a user or a company. The match is the provider's verified email, and after
 * the first sign-in the provider's own account id, so a changed email at the provider cannot hand the account over.
 */
class SsoSignInService
{
    /**
     * @param  array{provider:string, subject:string, email:string, verified:bool}  $identity
     *
     * @throws SsoException
     */
    public function resolve(array $identity): User
    {
        if ($identity['subject'] === '' || $identity['email'] === '' || ! $identity['verified']) {
            throw new SsoException(__(':provider did not confirm this email address, so it cannot be used to sign in.', ['provider' => SsoGateway::PROVIDERS[$identity['provider']]]));
        }

        return DB::transaction(function () use ($identity) {
            $link = SsoIdentity::withoutGlobalScopes()->where('provider', $identity['provider'])->where('subject', $identity['subject'])->first();

            $user = $link
                ? User::find($link->user_id)
                : User::whereRaw('lower(email) = ?', [$identity['email']])->first();

            if (! $user) {
                throw new SsoException(__('There is no Nirmaan360 account for this email. Ask your company owner to add you, then try again.'));
            }

            if (! $link && SsoIdentity::withoutGlobalScopes()->where('user_id', $user->id)->where('provider', $identity['provider'])->exists()) {
                throw new SsoException(__('This account is already linked to a different :provider account.', ['provider' => SsoGateway::PROVIDERS[$identity['provider']]]));
            }

            $organization = $user->organization_id ? Organization::find($user->organization_id) : null;

            if (! $user->is_active || ! $organization) {
                throw new SsoException(__('This account has been deactivated.'));
            }

            if (! $organization->allowsFeature('sso')) {
                throw new SsoException(__('Single sign-on is not part of your company plan. Sign in with your password instead.'));
            }

            SsoIdentity::withoutGlobalScopes()->updateOrCreate(
                ['provider' => $identity['provider'], 'subject' => $identity['subject']],
                ['organization_id' => $user->organization_id, 'user_id' => $user->id, 'email' => $identity['email'], 'last_login_at' => now()],
            );
            AuditLog::record('sso.login', $user, null, ['provider' => $identity['provider'], 'linked_now' => $link === null]);

            return $user;
        });
    }
}
