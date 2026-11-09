<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Services\SampleDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class AddSampleData extends Command
{
    protected $signature = 'sample:fill {email : Email of an owner account that has already registered}';

    protected $description = 'Add sample projects and site records to one company, so its owner can see the product working';

    public function handle(SampleDataService $sample): int
    {
        $owner = User::where('email', $this->argument('email'))->first();

        if (! $owner || $owner->role !== 'owner') {
            $this->error('No owner account uses that email. Register on the website first, then run this again.');

            return self::FAILURE;
        }

        $organization = $owner->organization;

        $alreadyFilled = Project::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', SampleDataService::TOWER_CODE)
            ->exists();

        if ($alreadyFilled) {
            $this->info("{$organization->name} already has the sample projects. Nothing was added.");

            return self::SUCCESS;
        }

        // Tenant scoping and audit logs both read the signed-in user.
        Auth::setUser($owner);

        try {
            $projects = $sample->fillCompany($organization, $owner);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage().' Nothing was added.');

            return self::FAILURE;
        }

        $names = implode(', ', array_map(fn (Project $project) => $project->name, $projects));
        $this->info("Added to {$organization->name} ({$organization->plan} plan, owner {$owner->name}): {$names}.");

        return self::SUCCESS;
    }
}
