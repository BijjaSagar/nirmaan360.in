<?php

namespace App\Services\Notify;

use App\Models\Alert;
use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialShortDelivery;
use App\Models\MaterialUnit;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\SiteAlerts\Sources\MaterialSource;
use App\Support\Dec;
use App\Support\Figure;

/**
 * Tells the people who can write a reason on a project when one is needed: a delivery came short
 * of its challan, or the material check found a gap (used beyond the BOQ, or received and gone).
 * Push only, and quantities only, so site staff may be told.
 */
class MaterialNotifier
{
    /** Held by everyone who may write the reason for a material gap. */
    public const REASON = 'material_control.reason';

    /** Site alerts that a written reason answers, and the flag in the material check each one stands for. */
    private const GAP_ALERTS = [
        MaterialSource::OVER_BOQ => MaterialCheckService::OVER_USED,
        MaterialSource::UNACCOUNTED => MaterialCheckService::UNACCOUNTED,
    ];

    public function __construct(private Notifier $notifier) {}

    /**
     * Called by AlertNotifier, after commit, for every alert that opens or turns critical. A material
     * gap closes when someone writes its reason, so whoever can write it on this project is pushed,
     * whether or not they manage alerts. The key is the alert's own, so a manager who was already
     * pushed the alert is not pushed this as well.
     */
    public function gapOpened(Project $project, Alert $alert, string $key): void
    {
        $flag = self::GAP_ALERTS[$alert->type] ?? null;

        if ($flag === null) {
            return;
        }

        $permissions = array_values(array_filter([self::REASON, $alert->needs_permission]));

        foreach (Audience::staff($alert->organization_id, $alert->project_id, $permissions) as $user) {
            $this->notifier->pushOnly($user, fn (string $locale) => new NotificationMessage(
                event: 'material.reason_needed',
                title: __('Material gap needs a reason · :project', ['project' => $project->name], $locale),
                body: $alert->messageIn($locale),
                url: $alert->url ?? route('material_control.index', $project->id),
                critical: $alert->severity === Alert::CRITICAL,
                push: new PushTarget(
                    category: PushCategory::MATERIAL,
                    type: 'material_flag',
                    id: (int) ($alert->data['material_id'] ?? 0),
                    organizationId: (int) $alert->organization_id,
                    projectId: (int) $alert->project_id,
                    permissions: $permissions,
                    dedupeKey: $key,
                    extra: ['alert_id' => $alert->id, 'flag' => $flag, 'severity' => $alert->severity],
                ),
            ));
        }
    }

    public function shortDelivery(MaterialShortDelivery $short, User $by): void
    {
        $this->notifier->whenCommitted(function () use ($short, $by) {
            // Read without the tenant scope, so what is sent never depends on who happens to be signed in.
            $challan = DeliveryChallan::withoutGlobalScopes()->find($short->delivery_challan_id);
            $project = Project::withoutGlobalScopes()->find($short->project_id);

            if ($challan === null || $project === null) {
                return;
            }

            $words = [
                'material' => (string) Material::withoutGlobalScopes()->find($short->material_id)?->name,
                'qty' => Figure::trim(Dec::qty($short->short_qty)),
                'unit' => (string) MaterialUnit::withoutGlobalScopes()->find($short->unit_id)?->code,
                'number' => $challan->number,
                'supplier' => (string) Party::withoutGlobalScopes()->find($short->party_id)?->name,
            ];

            foreach (Audience::staff($short->organization_id, $short->project_id, [self::REASON]) as $user) {
                // Whoever counted the delivery already knows.
                if ($user->id === $by->id) {
                    continue;
                }

                $this->notifier->pushOnly($user, fn (string $locale) => new NotificationMessage(
                    event: 'material.short_delivery',
                    title: __('Short delivery needs a reason · :project', ['project' => $project->name], $locale),
                    body: __(':material: :qty :unit short on challan :number from :supplier.', $words, $locale),
                    url: route('material_control.index', $project->id),
                    push: new PushTarget(
                        category: PushCategory::MATERIAL,
                        type: 'delivery_challan',
                        id: $challan->id,
                        organizationId: (int) $short->organization_id,
                        projectId: (int) $short->project_id,
                        permissions: [self::REASON],
                        dedupeKey: 'short_delivery:'.$short->id,
                        extra: ['material_id' => $short->material_id, 'flag' => 'short_delivery'],
                    ),
                ));
            }
        });
    }
}
