<?php

namespace App\Services\SiteAlerts;

use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use App\Services\Notify\Audience;
use App\Services\Notify\MaterialNotifier;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use App\Services\Notify\PushCategory;
use App\Services\Notify\PushTarget;
use Closure;

/**
 * Decides who hears about an alert and tells them through the shared Notifier, in their own
 * language. Someone hears about an alert when they can see the project, hold alerts.view, hold
 * the alert's notify permission, and hold its money permission if it has one. So a site engineer
 * is never sent a rupee figure, and someone limited to chosen projects hears only about those.
 * An alert with no notify permission is for the dashboard only and nobody is told.
 *
 * On the mobile app the alert is pushed to everyone who can see it, not only the people it is
 * for: the same rules without the notify permission. Those extra people get the push alone.
 *
 * Critical alerts are told first, and a push about one is not held back by quiet hours. The push
 * names the alert so the app can open it, and carries a key for the cause and its severity, so a
 * scan that runs again, or an alert that closes and reopens the same day, is not pushed twice.
 */
class AlertNotifier
{
    private const ORDER = [Alert::CRITICAL => 0, Alert::WARNING => 1, Alert::INFO => 2];

    /** @var list<array{0: Project, 1: Alert}>|null Alerts kept back while a scan of many projects runs. Null outside one. */
    private ?array $held = null;

    public function __construct(private Notifier $notifier, private MaterialNotifier $material) {}

    /**
     * Runs a scan of many projects and tells people once it has finished, critical alerts first
     * across every project, so the urgent ones are at the head of the queue.
     */
    public function batch(Closure $scan): mixed
    {
        if ($this->held !== null) {
            return $scan();
        }

        $this->held = [];

        try {
            return $scan();
        } finally {
            $held = $this->held;
            $this->held = null;
            $this->tell($held);
        }
    }

    /** @param  list<Alert>  $alerts  Alerts that have just opened or just become critical. */
    public function opened(Project $project, array $alerts): void
    {
        $found = array_map(fn (Alert $alert) => [$project, $alert], array_values($alerts));

        if ($this->held !== null) {
            $this->held = [...$this->held, ...$found];

            return;
        }

        $this->tell($found);
    }

    /** @param  list<array{0: Project, 1: Alert}>  $found */
    private function tell(array $found): void
    {
        if ($found === []) {
            return;
        }

        // Critical first. The sort is stable, so alerts of one severity keep the order they were found in.
        usort($found, fn (array $a, array $b) => (self::ORDER[$a[1]->severity] ?? 3) <=> (self::ORDER[$b[1]->severity] ?? 3));

        $this->notifier->whenCommitted(function () use ($found) {
            $teams = [];

            foreach ($found as [$project, $alert]) {
                $teams[$project->id] ??= Audience::staff($project->organization_id, $project->id, ['alerts.view']);
                $key = $this->pushKey($alert);
                $write = fn (string $locale) => $this->message($project, $alert, $locale, $key);
                [$told, $others] = $teams[$project->id]->partition(fn (User $user) => $this->hears($user, $alert));

                foreach ($told as $user) {
                    $this->notifier->send($user, $write);
                }

                // A material gap also goes to whoever can write its reason. The shared key means nobody is pushed about it twice.
                $this->material->gapOpened($project, $alert, $key);

                // Everyone else who can see the alert is pushed it on the app and nothing more: the bell and
                // email stay with the people it is for. A dashboard-only alert is pushed to nobody.
                foreach ($alert->notify_permission === null ? [] : $others as $user) {
                    if ($this->sees($user, $alert)) {
                        $this->notifier->pushOnly($user, $write);
                    }
                }

                $alert->forceFill(['notified_at' => now()])->saveQuietly();
            }
        });
    }

    private function message(Project $project, Alert $alert, string $locale, string $key): NotificationMessage
    {
        return new NotificationMessage(
            event: 'alert.'.$alert->type,
            title: $alert->titleIn($locale).' · '.$project->name,
            body: $alert->messageIn($locale),
            url: $alert->url ?? route('alerts.index', $project),
            critical: $alert->severity === Alert::CRITICAL,
            push: new PushTarget(
                category: PushCategory::ALERTS,
                type: 'alert',
                id: $alert->id,
                organizationId: (int) $alert->organization_id,
                projectId: (int) $alert->project_id,
                // What it takes to see the alert. This is checked again when the push is sent.
                permissions: array_values(array_filter(['alerts.view', $alert->needs_permission])),
                dedupeKey: $key,
                extra: ['alert_type' => $alert->type, 'severity' => $alert->severity],
            ),
        );
    }

    /** One cause at one severity. An alert that turns critical has a new key, so people hear about it again. */
    private function pushKey(Alert $alert): string
    {
        return 'alert:'.$alert->project_id.':'.$alert->type.'|'.$alert->reference.':'.$alert->severity;
    }

    /** Told in full: the bell, email and the rest, as well as the push. */
    private function hears(User $user, Alert $alert): bool
    {
        return $alert->notify_permission !== null
            && $user->hasPermission($alert->notify_permission)
            && $this->sees($user, $alert);
    }

    /** May open the alert at all. Everyone here already holds alerts.view on the project. */
    private function sees(User $user, Alert $alert): bool
    {
        return $alert->needs_permission === null || $user->hasPermission($alert->needs_permission);
    }
}
