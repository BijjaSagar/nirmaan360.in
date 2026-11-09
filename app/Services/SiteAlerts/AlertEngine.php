<?php

namespace App\Services\SiteAlerts;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Services\SiteAlerts\Contracts\AlertSource;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs every registered alert source over a project, opens an alert for each new cause, keeps
 * the open ones fresh and resolves those whose cause has cleared. One cause is identified by
 * its type and reference, so a second scan never opens a second alert for it.
 */
class AlertEngine
{
    public function __construct(private AlertNotifier $notifier) {}

    /**
     * Every source any module has registered under alert_sources.
     *
     * @return list<AlertSource>
     */
    public function sources(): array
    {
        $sources = [];

        foreach (config('modules', []) as $module) {
            foreach ($module['alert_sources'] ?? [] as $class) {
                $sources[$class] ??= app($class);
            }
        }

        return array_values($sources);
    }

    /**
     * Every live project of every company whose plan has site control. Runs from the scheduler
     * without a signed-in user, so each query filters by organisation itself.
     *
     * @return array{projects:int, new:int, resolved:int}
     */
    public function scanAll(?CarbonInterface $today = null): array
    {
        $totals = ['projects' => 0, 'new' => 0, 'resolved' => 0];

        Organization::query()->chunkById(100, function ($organizations) use ($today, &$totals) {
            foreach ($organizations as $organization) {
                if (! $organization->allowsFeature('site_control')) {
                    continue;
                }

                $projects = Project::withoutGlobalScopes()
                    ->where('organization_id', $organization->id)
                    ->whereIn('status', ['planning', 'active'])
                    ->whereNull('deleted_at')
                    ->get();

                foreach ($projects as $project) {
                    $result = $this->scanProject($project, $today);
                    $totals['projects']++;
                    $totals['new'] += $result['new'];
                    $totals['resolved'] += $result['resolved'];
                }
            }
        });

        return $totals;
    }

    /** @return array{new:int, open:int, resolved:int} */
    public function scanProject(Project $project, ?CarbonInterface $today = null): array
    {
        [$findings, $types] = $this->collect($project, $today ?? now());

        [$tell, $new, $open, $resolved] = DB::transaction(function () use ($project, $findings, $types) {
            $alerts = Alert::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('status', Alert::OPEN)
                ->get()
                ->keyBy('open_key');

            $tell = [];
            $new = 0;
            $seen = [];

            foreach ($findings as $finding) {
                $key = $finding->key();

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $alert = $alerts->get($key);

                if ($alert === null) {
                    $tell[] = $this->open($project, $finding);
                    $new++;
                } elseif ($this->refresh($alert, $finding)) {
                    $tell[] = $alert;
                }
            }

            $resolved = 0;

            foreach ($alerts as $key => $alert) {
                if (! isset($seen[$key]) && in_array($alert->type, $types, true)) {
                    $this->resolve($alert);
                    $resolved++;
                }
            }

            return [$tell, $new, count($seen), $resolved];
        });

        if ($tell !== []) {
            $this->notifier->opened($project, $tell);
        }

        return ['new' => $new, 'open' => $open, 'resolved' => $resolved];
    }

    /**
     * Findings from every source, and the types of the sources that answered. A source that
     * fails is logged and skipped, and its alerts are left as they are because nobody can say
     * whether their cause has cleared.
     *
     * @return array{0: list<Finding>, 1: list<string>}
     */
    private function collect(Project $project, CarbonInterface $today): array
    {
        $findings = [];
        $types = [];

        foreach ($this->sources() as $source) {
            try {
                $found = [];

                foreach ($source->findings($project, $today) as $finding) {
                    $found[] = $finding;
                }

                $findings = [...$findings, ...$found];
                $types = [...$types, ...$source->types()];
            } catch (Throwable $exception) {
                Log::warning('Alert source failed', ['source' => $source::class, 'project_id' => $project->id, 'error' => $exception->getMessage()]);
            }
        }

        return [$findings, array_values(array_unique($types))];
    }

    private function open(Project $project, Finding $finding): Alert
    {
        $alert = Alert::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'type' => $finding->type,
            'reference' => $finding->reference,
            'severity' => $finding->severity,
            'title' => $finding->title,
            'message' => $finding->message,
            'params' => $finding->params,
            'data' => $finding->data,
            'needs_permission' => $finding->needs,
            'notify_permission' => $finding->notify,
            'url' => $finding->url,
            'status' => Alert::OPEN,
            'open_key' => $finding->key(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        AuditLog::record('alert.opened', $alert, null, ['type' => $alert->type, 'reference' => $alert->reference, 'severity' => $alert->severity]);

        return $alert;
    }

    /** Keeps the figures current. True when the alert has just become critical, so people hear about it again. */
    private function refresh(Alert $alert, Finding $finding): bool
    {
        $escalated = $alert->severity !== Alert::CRITICAL && $finding->severity === Alert::CRITICAL;

        $alert->update([
            'severity' => $finding->severity,
            'title' => $finding->title,
            'message' => $finding->message,
            'params' => $finding->params,
            'data' => $finding->data,
            'url' => $finding->url,
            'last_seen_at' => now(),
        ]);

        if ($escalated) {
            AuditLog::record('alert.escalated', $alert, ['severity' => $alert->getOriginal('severity')], ['severity' => Alert::CRITICAL]);
        }

        return $escalated;
    }

    private function resolve(Alert $alert): void
    {
        $alert->update(['status' => Alert::RESOLVED, 'resolved_at' => now(), 'open_key' => null]);

        AuditLog::record('alert.resolved', $alert, ['status' => Alert::OPEN], ['status' => Alert::RESOLVED]);
    }
}
