<?php

namespace App\Services\Planning;

use App\Models\Organization;
use App\Models\PlanActivity;
use App\Models\PlanDelayAlert;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PlanningDelayAlert;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Finds delayed activities and tells the planners, once per delay. Work is only counted
 * when its measurements are approved, which can lag the site by a day or two, so a delay
 * must be older than the grace period before anyone is told.
 */
class DelayAlertService
{
    private const GRACE_DAYS = 2;

    private const NAMED_IN_NOTICE = 3;

    public function __construct(private ProgressService $progress) {}

    /** @return array{projects:int, new:int, resolved:int} Runs without a signed-in user, so every query filters by organisation. */
    public function scanAll(?CarbonInterface $today = null): array
    {
        $totals = ['projects' => 0, 'new' => 0, 'resolved' => 0];

        Organization::query()->chunkById(100, function ($organizations) use ($today, &$totals) {
            foreach ($organizations as $organization) {
                if (! $organization->allowsFeature('planning')) {
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

    /**
     * Record new delays, close those that have cleared, and send one notice covering the new ones.
     *
     * @return array{new:int, open:int, resolved:int}
     */
    public function scanProject(Project $project, ?CarbonInterface $today = null): array
    {
        $plan = $this->progress->snapshot($project, $today);
        $date = Days::toDate($plan->today);

        [$fresh, $open, $resolved] = DB::transaction(function () use ($plan, $project, $date) {
            $alerts = PlanDelayAlert::where('project_id', $project->id)
                ->get()
                ->keyBy(fn (PlanDelayAlert $alert) => $alert->plan_activity_id.':'.$alert->kind);

            $fresh = [];
            $seen = [];

            foreach ($plan->activities as $activity) {
                $progress = $plan->progress[$activity->id];

                if (! $progress->isDelayed() || $progress->delayDays <= self::GRACE_DAYS) {
                    continue;
                }

                $key = $activity->id.':'.$progress->delayKind;
                $seen[$key] = true;

                if ($this->record($alerts->get($key), $activity, $progress->delayKind, $progress->delayDays, $date)) {
                    $fresh[] = [$activity, $progress->delayKind, $progress->delayDays];
                }
            }

            $resolved = $alerts
                ->filter(fn (PlanDelayAlert $alert, string $key) => $alert->resolved_on === null && ! isset($seen[$key]))
                ->each(fn (PlanDelayAlert $alert) => $alert->update(['resolved_on' => $date]))
                ->count();

            return [$fresh, count($seen), $resolved];
        });

        if ($fresh !== []) {
            $this->notify($project, $fresh);
        }

        return ['new' => count($fresh), 'open' => $open, 'resolved' => $resolved];
    }

    /** @return bool True when this delay is new or has come back, and so needs a notice. */
    private function record(?PlanDelayAlert $alert, PlanActivity $activity, string $kind, int $days, string $date): bool
    {
        if ($alert === null) {
            PlanDelayAlert::create([
                'organization_id' => $activity->organization_id,
                'project_id' => $activity->project_id,
                'plan_activity_id' => $activity->id,
                'kind' => $kind,
                'days_late' => $days,
                'first_seen_on' => $date,
                'notified_at' => now(),
            ]);

            return true;
        }

        if ($alert->resolved_on !== null) {
            $alert->update(['days_late' => $days, 'first_seen_on' => $date, 'notified_at' => now(), 'resolved_on' => null]);

            return true;
        }

        $alert->update(['days_late' => $days]);

        return false;
    }

    /** @param list<array{0:PlanActivity, 1:string, 2:int}> $fresh */
    private function notify(Project $project, array $fresh): void
    {
        usort($fresh, fn (array $a, array $b) => $b[2] <=> $a[2]);

        $planners = User::where('organization_id', $project->organization_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('planning.manage'));

        foreach ($planners as $user) {
            $locale = $user->locale ?: config('app.locale');

            $named = array_map(
                fn (array $delay) => $this->describe($delay, $locale),
                array_slice($fresh, 0, self::NAMED_IN_NOTICE),
            );

            $more = count($fresh) - count($named);

            if ($more > 0) {
                $named[] = __('and :count more', ['count' => $more], $locale);
            }

            $user->notify(new PlanningDelayAlert(
                title: __('Delay alert: :project', ['project' => $project->name], $locale),
                body: implode(', ', $named),
                url: route('planning.index', $project),
            ));
        }
    }

    /** @param array{0:PlanActivity, 1:string, 2:int} $delay */
    private function describe(array $delay, string $locale): string
    {
        [$activity, $kind, $days] = $delay;

        $note = $kind === ActivityProgress::OVERDUE
            ? __('overdue by :days days', ['days' => $days], $locale)
            : __('start late by :days days', ['days' => $days], $locale);

        return $activity->code.' '.$activity->name.' ('.$note.')';
    }
}
