# Adding a module

A module plugs in from its own files, so several features can be built at once without editing the same shared files. Follow `CLAUDE.md` as well.

## Files a module owns

| What | Where |
| --- | --- |
| Web routes | `routes/modules/<name>.php` (already inside the signed-in, active-user group) |
| Mobile API routes | `routes/api_modules/<name>.php` (already inside `/api/v1` with Sanctum) |
| Registration | `config/modules/<name>.php` |
| Controllers | `app/Http/Controllers/<Name>/` |
| Services | `app/Services/<Name>/` |
| Models | `app/Models/`, with a class name no other module would use |
| Views | `resources/views/modules/<name>/` |
| Translations | `lang/modules/<name>/hi.json` and `mr.json` |
| Tests | `tests/Feature/<Name>/` |

Use controller classes in routes, never closures, so routes can be cached.

## Registration file

```php
return [
    // Name used with the feature:<name> route middleware. Leave out for a module on every plan.
    'feature' => 'crm',
    // Plans that include it.
    'plans' => ['contractor', 'business', 'enterprise'],
    // Added to each role. Owner and admin already have everything.
    'permissions' => [
        'pm' => ['crm.*'],
        'accounts' => ['crm.view'],
    ],
    // place: "project" (inside a project, the route receives the project) or "company".
    'nav' => [
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Tenders', 'route' => 'crm.tenders.index', 'active' => 'crm.tenders.*', 'can' => 'crm.view', 'order' => 10],
    ],
    // Extra icons for <x-icon>: name => SVG inner markup on a 24 × 24 stroke grid.
    'icons' => [],
];
```

Protect every route with `feature:<name>` when the module has a feature, and with `can:<module>.<action>`.

## Shared rules for parallel work

- Do not edit `routes/web.php`, `routes/api.php`, `config/buildos.php`, `lang/hi.json`, `lang/mr.json`, `components/icon.blade.php` or `layouts/app.blade.php`. If a module truly needs a change there, make the smallest possible edit and say so in your report.
- Do not add Composer or npm packages. `phpoffice/phpspreadsheet`, `laravel/socialite`, `pdfjs-dist` and `konva` are already installed. Ask for anything else in your report.
- Use the migration date assigned to your module so timestamps never collide. Migrations must be additive and have a working `down()`.
- A link to another module's table is a nullable id with no foreign key, so modules can merge in any order.
- Do not touch `mobile/`.
- Site staff never receive money. Rates, amounts, contract values and payments need `money.view`; budget and total spend need `money.overview`. Enforce this in the controller, API presenter, export and view alike, not only by hiding a column. Work out progress only through `App\Services\SiteControl\WorkProgress`.
- Every tenant model needs a `project_id` column, a `PROJECT_VIA` constant naming the relation that leads to one, or `COMPANY_WIDE = true`. Without this, people limited to chosen projects could read the record by id.
- An outside service (WhatsApp, FCM, SMS, AI model, Tally, SSO) sits behind a small gateway class that reads its keys from `PlatformSettings` or `config/services.php`. With no keys it must report "not connected" clearly, and tests use a fake. Never fake a success, and never show a feature as live when it is not connected.
- Never put keys, passwords or personal details in the repository. The repository is public.
- Never change production, push to `main`, or use real accounts.

## Definition of done

Every module needs feature tests for permission and tenant isolation, service tests for money or quantity logic, UI text through `__()` with Hindi and Marathi translations, and a phone-width check of its screens. `php artisan test` must pass, `vendor/bin/pint --dirty` must be clean, and `npm run build` must work.

## Plugging into site alerts and the site dashboard

The `site_alerts` module runs other modules' checks and shows their cards without knowing them. Add keys to your own `config/modules/<name>.php`:

| Key | Each class implements | What it does |
| --- | --- | --- |
| `alert_sources` | `App\Services\SiteAlerts\Contracts\AlertSource` | `types()` lists the alert types you raise; `findings(Project, today)` returns a `Finding` for every cause present today. The engine opens, refreshes and resolves alerts by type and reference, and tells people through `Notifier`. Runs daily (`site-alerts:scan`) and on demand. |
| `dashboard_cards` | `App\Services\SiteAlerts\Contracts\DashboardCard` | `cards(Project, viewer, today)` yields `Card` objects, rendered on `/projects/{project}/site-dashboard` and returned by the mobile API as they are. |
| `spend_sources` | `App\Services\SiteAlerts\Contracts\SpendSource` | `spent(Project)` is money already gone from your records, counted once, added into spend against budget. |

A `Finding` with a rupee figure must set `needs` to `money.view` or `money.overview`; it is then hidden from, and never sent to, anyone without that permission. Its `notify` names the permission of the people told when it opens, or is null when your module has already told them itself. A card shows money only when `$viewer->can('money.overview')`. Work percentages come from `App\Services\SiteControl\WorkProgress`.
