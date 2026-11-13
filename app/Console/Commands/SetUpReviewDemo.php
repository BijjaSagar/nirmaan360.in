<?php

namespace App\Console\Commands;

use App\Exceptions\ReviewDemoRefused;
use App\Models\Organization;
use App\Services\Access\ReviewDemoCompany;
use Illuminate\Console\Command;

/** Parses and prints only. The work is in ReviewDemoCompany, and nothing it hands back is secret. */
class SetUpReviewDemo extends Command
{
    protected $signature = 'review:demo-company
        {owner_email : Email for the demo owner. It must have no account yet, unless --top-up is given}
        {--contractor= : Email for a contractor-portal login on the sample subcontractor}
        {--company= : Company name. Default: Demo Builders (Sample)}
        {--name= : Owner name. Default: Review Owner}
        {--top-up : Add approvals waiting to a demo company this command made, and change nothing else}';

    protected $description = 'Make the separate company that App Store and Google Play reviewers sign in to, or top up its waiting approvals';

    public function handle(ReviewDemoCompany $demo): int
    {
        $email = (string) $this->argument('owner_email');

        try {
            if ($this->option('top-up')) {
                if (filled($this->option('contractor')) || filled($this->option('company')) || filled($this->option('name'))) {
                    throw new ReviewDemoRefused('--top-up takes the owner email only. Nothing was changed.');
                }

                $this->printTopUp($demo->topUp($email));
            } else {
                $this->printSetUp($demo->setUp($email, $this->option('contractor'), $this->option('company'), $this->option('name')));
            }
        } catch (ReviewDemoRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function printSetUp(array $result): void
    {
        /** @var Organization $organization */
        $organization = $result['organization'];

        $this->info('Demo company made.');
        $this->line("Company: #{$organization->id} {$organization->name}");
        $this->planLine($organization);
        $this->line('Projects: '.implode(', ', $result['projects']));
        $this->waitingLine($result['waiting']);
        $this->line("AI agents: {$result['agents_off']} switched off, daily brief off.");
        $this->line('Announcement: '.($result['announcement'] ?? 'none'));

        $this->line('Emails:');

        foreach ($result['emails'] as $email) {
            $this->line("  {$email['what']} to {$email['to']}: ".($email['sent'] ? 'sent' : 'NOT sent'));
        }

        $this->line('Worker QR cards print at '.ReviewDemoCompany::CARDS_PATH.' ('.url(ReviewDemoCompany::CARDS_PATH).'), signed in as the owner.');

        if ($result['card'] !== null) {
            $this->line("QR card text for {$result['card']['worker']}, not yet marked today: {$result['card']['code']}");
        }

        foreach ($result['problems'] as $problem) {
            $this->warn($problem);
        }
    }

    private function printTopUp(array $result): void
    {
        /** @var Organization $organization */
        $organization = $result['organization'];

        $this->info("Topped up #{$organization->id} {$organization->name}.");
        $this->planLine($organization);

        if ($result['added'] === []) {
            $this->line("Nothing added: {$result['project']} already has a daily report waiting at the project manager and a measurement waiting at the QS.");
        }

        foreach ($result['added'] as $added) {
            $this->line("Added: {$added}");
        }

        $this->waitingLine($result['waiting']);
        $this->line($result['today_free']
            ? "Today's daily report on {$result['project']} is free."
            : "Today's daily report on {$result['project']} was already written, and was left as it is.");
    }

    private function planLine(Organization $organization): void
    {
        $label = config("buildos.plans.{$organization->plan}.label", $organization->plan);
        $ends = $organization->trial_ends_at === null && $organization->paid_until === null
            ? 'no trial end and no paid-until date, so it does not lapse'
            : 'trial ends '.($organization->trial_ends_at?->toDateString() ?? 'never').', paid until '.($organization->paid_until?->toDateString() ?? 'not set');

        $this->line("Plan: {$label}, {$organization->plan_status}; {$ends}.");
    }

    /** @param  array<string, int>  $waiting */
    private function waitingLine(array $waiting): void
    {
        $parts = collect($waiting)->map(fn (int $count, string $kind) => "{$kind}: {$count}")->values()->all();

        $this->line('Approvals waiting for the owner: '.array_sum($waiting).($parts === [] ? '' : ' ('.implode(', ', $parts).')'));
    }
}
