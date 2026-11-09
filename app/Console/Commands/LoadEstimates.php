<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Reports\EstimateProjects;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class LoadEstimates extends Command
{
    protected $signature = 'boq:load-estimates {email : Email of an owner account} {path : File holding the estimates as base64 of gzipped JSON}';

    protected $description = 'Create projects with their BOQ in one company from a packed set of estimate sheets';

    public function handle(EstimateProjects $projects): int
    {
        $owner = User::where('email', $this->argument('email'))->first();

        if (! $owner || $owner->role !== 'owner') {
            $this->error('No owner account uses that email.');

            return self::FAILURE;
        }

        $estimates = $this->read((string) $this->argument('path'));

        if ($estimates === null) {
            $this->error('That file could not be read as a packed set of estimates. Nothing was added.');

            return self::FAILURE;
        }

        // Tenant scoping and audit logs both read the signed-in user.
        Auth::setUser($owner);

        foreach ($estimates as $estimate) {
            try {
                ['project' => $project, 'result' => $result, 'value' => $value] = $projects->load($owner, $estimate);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info("{$project->name} [{$project->code}]: {$result->created} added, {$result->updated} updated, {$result->unchanged} unchanged. BOQ value {$value}.");

            foreach ($result->notes as $note) {
                $this->line('  row '.$note['row'].': '.$note['message']);
            }
        }

        return self::SUCCESS;
    }

    /** @return ?list<array<string, mixed>> */
    private function read(string $path): ?array
    {
        $packed = is_file($path) ? base64_decode(trim((string) file_get_contents($path)), true) : false;
        $json = $packed === false ? false : @gzdecode($packed);
        $estimates = $json === false ? null : json_decode($json, true);

        return is_array($estimates) && array_is_list($estimates) ? $estimates : null;
    }
}
