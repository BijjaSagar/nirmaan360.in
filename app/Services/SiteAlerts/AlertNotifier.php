<?php

namespace App\Services\SiteAlerts;

use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;

/**
 * Decides who hears about an alert and tells them through the shared Notifier, in their own
 * language. Someone hears about an alert when they can see the project, hold alerts.view, hold
 * the alert's notify permission, and hold its money permission if it has one. So a site engineer
 * is never sent a rupee figure, and someone limited to chosen projects hears only about those.
 * An alert with no notify permission is for the dashboard only and nobody is told.
 */
class AlertNotifier
{
    public function __construct(private Notifier $notifier) {}

    /** @param  list<Alert>  $alerts  Alerts that have just opened or just become critical. */
    public function opened(Project $project, array $alerts): void
    {
        $this->notifier->whenCommitted(function () use ($project, $alerts) {
            $people = User::where('organization_id', $project->organization_id)
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $user) => $user->hasPermission('alerts.view') && $this->seesProject($user, $project));

            foreach ($alerts as $alert) {
                foreach ($people as $user) {
                    if ($this->hears($user, $alert)) {
                        $this->notifier->send($user, $this->message($project, $alert, $user->locale ?: config('app.locale')));
                    }
                }

                $alert->forceFill(['notified_at' => now()])->saveQuietly();
            }
        });
    }

    private function message(Project $project, Alert $alert, string $locale): NotificationMessage
    {
        return new NotificationMessage(
            event: 'alert.'.$alert->type,
            title: $alert->titleIn($locale).' · '.$project->name,
            body: $alert->messageIn($locale),
            url: $alert->url ?? route('alerts.index', $project),
            critical: $alert->severity === Alert::CRITICAL,
        );
    }

    private function seesProject(User $user, Project $project): bool
    {
        return ! $user->isProjectLimited() || $user->assignedProjectIds()->where('project_id', $project->id)->exists();
    }

    private function hears(User $user, Alert $alert): bool
    {
        return $alert->notify_permission !== null
            && $user->hasPermission($alert->notify_permission)
            && ($alert->needs_permission === null || $user->hasPermission($alert->needs_permission));
    }
}
