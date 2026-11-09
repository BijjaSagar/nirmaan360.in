<?php

use App\Services\SiteAlerts\Cards\AlertsCard;
use App\Services\SiteAlerts\Cards\ContractorCard;
use App\Services\SiteAlerts\Cards\MaterialCard;
use App\Services\SiteAlerts\Cards\MoneyCard;
use App\Services\SiteAlerts\Cards\PendingCard;
use App\Services\SiteAlerts\Cards\PermitsCard;
use App\Services\SiteAlerts\Cards\StagesCard;
use App\Services\SiteAlerts\Cards\TimeCard;
use App\Services\SiteAlerts\Cards\TradeCards;
use App\Services\SiteAlerts\Cards\WorkCard;
use App\Services\SiteAlerts\Sources\BillsPendingSource;
use App\Services\SiteAlerts\Sources\BudgetSource;
use App\Services\SiteAlerts\Sources\MaterialSource;
use App\Services\SiteAlerts\Sources\MepPendingSource;
use App\Services\SiteAlerts\Sources\PaymentAheadSource;
use App\Services\SiteAlerts\Sources\ScheduleSource;
use App\Services\SiteAlerts\Sources\ShortDeliverySource;
use App\Services\SiteAlerts\Spend\PettyCashSpend;
use App\Services\SiteAlerts\Spend\SubcontractorBillSpend;
use App\Services\SiteAlerts\Spend\VendorBillSpend;

/*
 * Alerts and the project manager's site dashboard, on the site_control feature. See docs/MODULES.md.
 *
 * Other modules plug in from their own config/modules/<name>.php without this module knowing them:
 *   alert_sources    classes implementing App\Services\SiteAlerts\Contracts\AlertSource
 *   dashboard_cards  classes implementing App\Services\SiteAlerts\Contracts\DashboardCard
 *   spend_sources    classes implementing App\Services\SiteAlerts\Contracts\SpendSource
 *
 * The sources and cards below that read contractor, material, stage-tracker and BOQ budget figures
 * reuse those modules' services and could move to their configs unchanged.
 */
return [
    'feature' => 'site_control',
    'plans' => ['contractor', 'business', 'enterprise'],

    // Everyone on a site sees alerts about quantities and time. Money alerts also need money.view or
    // money.overview (config/modules/site_control.php), which site staff never hold.
    'permissions' => [
        'pm' => ['alerts.*'],
        'qs' => ['alerts.view'],
        'accounts' => ['alerts.view'],
        'engineer' => ['alerts.view'],
        'store' => ['alerts.view'],
        'viewer' => ['alerts.view'],
    ],

    'nav' => [
        ['place' => 'project', 'label' => 'Site dashboard', 'route' => 'site-dashboard.show', 'active' => 'site-dashboard.*', 'can' => 'progress.view', 'order' => 1],
        ['place' => 'project', 'label' => 'Alerts', 'route' => 'alerts.index', 'active' => 'alerts.index', 'can' => 'alerts.view', 'order' => 2],
        ['place' => 'company', 'label' => 'Alerts', 'route' => 'alerts.company', 'active' => 'alerts.company', 'can' => 'alerts.view', 'order' => 1],
    ],

    'icons' => [
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9z"/><path d="M10 20a2.2 2.2 0 0 0 4 0"/>',
        'gauge' => '<path d="M4 16a8 8 0 1 1 16 0"/><path d="m12 16 4.5-5.5"/><path d="M3 20h18"/>',
    ],

    'alert_sources' => [
        ScheduleSource::class,
        PaymentAheadSource::class,
        BudgetSource::class,
        MepPendingSource::class,
        MaterialSource::class,
        BillsPendingSource::class,
        ShortDeliverySource::class,
    ],

    'dashboard_cards' => [
        WorkCard::class,
        TimeCard::class,
        MoneyCard::class,
        ContractorCard::class,
        PendingCard::class,
        PermitsCard::class,
        AlertsCard::class,
        MaterialCard::class,
        StagesCard::class,
        TradeCards::class,
    ],

    // Money already gone on a project. Each source counts its own records once. Contractor payments
    // (contractor_control) are a register of their own and are shown on the contractor card, not added here.
    'spend_sources' => [
        SubcontractorBillSpend::class,
        VendorBillSpend::class,
        PettyCashSpend::class,
    ],

    // Time used % minus work done % from which a project counts as behind schedule. Twice this is critical.
    'schedule_lag_alert_pct' => 10,

    // Days before the deadline at which the deadline alert opens while work remains. Inside a week it is critical.
    'deadline_warning_days' => 30,

    // A delivery challan with no bill after this many days is raised for the accountant.
    'challan_bill_days' => 7,

    // BOQ categories (project_setup) that mean finishing has started on a floor once work is entered there,
    // and the words that mean the same on a line with no category.
    'finishing_categories' => ['plaster', 'finishing'],
    'finishing_match' => ['plaster', 'pop', 'paint', 'putty', 'gypsum', 'distemper'],

    // Trades the dashboard keeps a card for, because that is where most money is lost. A BOQ line belongs
    // to a trade by its category (project_setup); a line with no category is matched on the words below
    // in its section name, code and description.
    'focus_trades' => [
        'rcc' => ['label' => 'RCC', 'category' => 'rcc', 'match' => ['rcc', 'r.c.c', 'reinforced cement', 'm20', 'm25', 'm30', 'm35', 'm40', 'column', 'beam', 'slab', 'chajja', 'lintel']],
        'brick' => ['label' => 'Brick work', 'category' => 'brick_work', 'match' => ['brick', 'masonry', 'block work', 'blockwork', 'aac', 'siporex']],
    ],

    // BOQ lines shown on each trade card, largest value first.
    'trade_card_lines' => 4,
];
