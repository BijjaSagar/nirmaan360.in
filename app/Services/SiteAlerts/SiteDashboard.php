<?php

namespace App\Services\SiteAlerts;

use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Collects the cards every module has registered under dashboard_cards, in order. */
class SiteDashboard
{
    /** @return list<DashboardCard> */
    public function providers(): array
    {
        $providers = [];

        foreach (config('modules', []) as $module) {
            foreach ($module['dashboard_cards'] ?? [] as $class) {
                $providers[$class] ??= app($class);
            }
        }

        return array_values($providers);
    }

    /**
     * Built for one viewer, so a card has already left out what that person may not see.
     * A card that fails is logged and left out; the rest of the dashboard still shows.
     *
     * @return list<Card>
     */
    public function cards(Project $project, User $viewer, ?CarbonInterface $today = null): array
    {
        $today ??= now();
        $cards = [];

        foreach ($this->providers() as $provider) {
            try {
                foreach ($provider->cards($project, $viewer, $today) as $card) {
                    $cards[] = $card;
                }
            } catch (Throwable $exception) {
                Log::warning('Dashboard card failed', ['card' => $provider::class, 'project_id' => $project->id, 'error' => $exception->getMessage()]);
            }
        }

        usort($cards, fn (Card $a, Card $b) => $a->order <=> $b->order);

        return $cards;
    }
}
