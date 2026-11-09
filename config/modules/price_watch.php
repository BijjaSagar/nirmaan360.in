<?php

use App\Services\PriceWatch\Alerts\PriceAlertSource;
use App\Services\PriceWatch\Cards\PriceCard;
use App\Services\PriceWatch\Sources\SharedListSource;

/*
 * Price watch: a dated list of material prices per company, the price board, and what today's
 * prices do to each project's budget against the rates its BOQ assumed. See docs/MODULES.md.
 *
 * There is no live price feed. A price exists because a person typed it, uploaded it from Excel,
 * or took it from the shared list the platform admin keeps. A real data provider can be added
 * under "sources" later without touching the screens.
 */
return [
    'feature' => 'price_watch',
    'plans' => ['contractor', 'business', 'enterprise'],

    // Rates are money: the board needs money.view and the project impact needs money.overview
    // (config/modules/site_control.php). Site roles hold neither. Writing prices needs this as well.
    'permissions' => [
        'pm' => ['price_watch.manage'],
        'accounts' => ['price_watch.manage'],
    ],

    'nav' => [
        ['place' => 'company', 'group' => 'Procurement', 'label' => 'Material prices', 'route' => 'price-watch.index', 'active' => 'price-watch.index', 'can' => 'money.view', 'order' => 30],
        ['place' => 'project', 'group' => 'Project setup', 'label' => 'Price impact', 'route' => 'price-watch.project', 'active' => 'price-watch.project*', 'can' => 'money.overview', 'order' => 50],
    ],

    'icons' => [],

    'alert_sources' => [PriceAlertSource::class],

    'dashboard_cards' => [PriceCard::class],

    // Where prices can be pulled from, in order. Each class implements App\Services\PriceWatch\Contracts\PriceSource.
    // A provider that is not connected reports so and is skipped; nothing is ever shown as live unless it is.
    'sources' => [SharedListSource::class],

    // Defaults the platform admin can change from /admin/price-watch.
    // A price older than this many days is shown as out of date and raises no alert.
    'stale_after_days' => 30,
    // A current price this far above the BOQ rate (or above the previous price) opens a price alert. Twice this is critical.
    'rise_alert_pct' => 5,
    // A total budget impact at or above this share of the approved budget (or the BOQ value when no budget is set) opens an alert.
    'impact_alert_pct' => 1,

    // Rows accepted from one Excel upload.
    'upload_max_rows' => 500,

    // Added once to every company's board, never overwriting a material the company already has.
    'defaults' => [
        ['key' => 'cement', 'name' => 'Cement', 'unit' => 'bag'],
        ['key' => 'steel', 'name' => 'Steel', 'unit' => 'kg'],
        ['key' => 'sand', 'name' => 'Sand', 'unit' => 'brass'],
        ['key' => 'aggregate', 'name' => 'Aggregate', 'unit' => 'brass'],
        ['key' => 'bricks', 'name' => 'Bricks', 'unit' => 'nos'],
        ['key' => 'aac_blocks', 'name' => 'AAC blocks', 'unit' => 'nos'],
        ['key' => 'copper_wire', 'name' => 'Copper wire', 'unit' => 'm'],
        ['key' => 'water_tank', 'name' => 'Water tank', 'unit' => 'litre'],
    ],
];
