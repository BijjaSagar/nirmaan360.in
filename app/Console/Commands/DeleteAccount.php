<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Access\AccountDeletionService;
use Illuminate\Console\Command;

/**
 * For someone who cannot sign in and asked through the contact form. Support confirms the request by
 * replying to the account's email before running this. It does what "Delete my account" does, without
 * the password.
 */
class DeleteAccount extends Command
{
    protected $signature = 'account:delete {email : The email the account signs in with} {--force : Do not ask for confirmation}';

    protected $description = 'Delete one person\'s account, or schedule their company\'s deletion if they are its only owner';

    public function handle(AccountDeletionService $deletions): int
    {
        $user = User::findByEmail((string) $this->argument('email'));

        if (! $user || $user->anonymised_at !== null) {
            $this->error('No account uses that email.');

            return self::FAILURE;
        }

        $preview = $deletions->preview($user);
        $company = $preview['mode'] === AccountDeletionService::MODE_COMPANY;

        $this->line('Company: '.$preview['company_name']);
        $this->line('Other people in the company: '.$preview['other_people']);

        if ($company) {
            $this->line('Mode: company. This person is the only active owner, so the whole company is erased after '.$preview['erase_after']->format('d M Y').'. Any owner can cancel until then.');
        } else {
            $this->line('Mode: account. The name, email, mobile number and sign-in are removed now. Work records stay with the company as "Deleted user".');
        }

        if ($preview['pending'] !== null) {
            $this->line('A company deletion is already pending, for '.$preview['pending']['erase_after']->format('d M Y').'.');
        }

        if (! $this->option('force') && ! $this->confirm($company ? 'Schedule this company\'s deletion?' : 'Delete this account now? This cannot be undone.')) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $result = $deletions->deleteBySupport($user);

        if ($result['result'] === 'scheduled') {
            $this->info('Scheduled. The company will be erased after '.$result['erase_after']->format('d M Y').'.');
        } else {
            $this->info('Deleted. The account is anonymised.');
        }

        return self::SUCCESS;
    }
}
