<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakePlatformAdmin extends Command
{
    protected $signature = 'platform:admin {email : Email of an account that has already registered}';

    protected $description = 'Give an existing account access to the platform admin area';

    public function handle(): int
    {
        // Emails are not verified at sign-up, so access is only ever granted to an account that already exists.
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No account uses that email. Register on the website first, then run this again.');

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => true])->save();
        $this->info("{$user->name} ({$user->organization?->name}) is now a platform admin.");

        return self::SUCCESS;
    }
}
