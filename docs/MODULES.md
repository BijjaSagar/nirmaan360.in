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
  `App\Support\Money::visible()` / `overview()` answer the question in code and `Money::orNull()` keeps a JSON key while blanking its figure; views use `@can('money.view')`. `tests/Feature/SiteControl/MoneyVisibilityTest` walks every signed-in GET route as engineer, store keeper and viewer and fails on any rupee figure, so a new route needs a row for its model: a module adds its own in `tests/Feature/SiteControl/MoneyWorld/<module>.php`, and core models go in `BuildsMoneyWorld` (the walk names the model it is missing).
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

## Website pages, search engines and analytics

Every page passes through `resources/views/modules/seo/head.blade.php`, included by `layouts/partials/head`. It marks the page `noindex` unless it is a listed website page, so a new app screen needs nothing.

- A new public page must be added to `App\Services\Seo\PublicPages`, with its title and description in `SeoText`, or named in `NOT_LISTED` in `tests/Feature/Seo/SitemapTest` with the reason it stays out of search. That test fails until one of the two is done. A page offered in Hindi and Marathi also needs a route in `routes/seo.php` and every line translated (`LanguageVersionsTest`).
- Do not write a title, description, canonical link or share tag in a view. They come from `/admin/seo`, where the platform admin edits them per language.
- To count something in Google Analytics, add the event and the only values it may carry to `App\Services\Seo\Analytics::events()`. Then call `Analytics::record()` on the server, or put `data-track` on the form or section. Never pass a name, email, phone number, company, project or amount. The page address sent is the route's pattern, so route parameters and query strings stay on the site.

## Pushing to the mobile app

Tell people through `App\Services\Notify\Notifier`, after commit (`whenCommitted`). `send()` reaches the bell, email, WhatsApp and the person's phones; `pushOnly()` reaches the phones alone, for people the other channels were never told about. Pass a closure that takes a language and returns the `NotificationMessage`, so each phone is pushed in the language its app is set to.

Give the message a `PushTarget` so the app can open the record: a `category` from `PushCategory` (the switch in the person's profile), a `type` and `id` (the types the app knows are listed on `PushTarget`; a new one needs the app to learn it), the company, the project, the permissions the reader must hold, and a `dedupeKey` when the same thing could be found twice. Set `critical` only for something worth waking a person for.

A site alert needs none of this: the engine pushes every new alert to everyone who can see it (`alerts.view`, the project, and `needs` when set), critical ones first. `notify` decides who also gets the bell and the email, and an alert whose `notify` is null stays on the dashboard and is pushed to nobody.

`PushDispatcher` then applies the rules for you, when the message is written and again when it is sent: the person's switches, quiet hours (10 pm to 7 am India time unless changed; a critical push is not held), one push per key per person per day, the company, the permissions and the project limit, and no rupee figure to anyone without `money.view`. Write the words without amounts for such a reader, as `approvalTitleFor()` does; a push that still carries one is withheld and logged.

Firebase's service account is saved under Platform admin > Integrations, encrypted like the other integration secrets. The `FIREBASE_CREDENTIALS_BASE64` environment variable (the same file in base64) is used only while nothing is saved there. With neither, push is off and nothing else changes.

A push may carry a picture: pass a public `https` address as `image` on the `NotificationMessage`. `FcmGateway` puts it in the notification for Android and in `fcm_options.image` with `mutable-content` for the iPhone app's notification service extension, and drops anything that is not `https` (plain `http` only on a local machine).

Broadcasts and reminders written by people, rather than by a module, are announcements: Platform admin > Announcements for every company or some of them, and Account > Announcements for a company's own admin (`settings.manage`). `AnnouncementService` sends them through `PushDispatcher` under the `announcements` switch, now, at a set time or every day or week (`announcements:send-due` runs every minute), keeps who each one reached in `announcement_recipients`, and the app lists them from `GET /announcements`. "Everyone" means the team: contractor logins get one only when the contractor role is ticked. The writer must answer "Does this message mention money?". Yes, or a rupee figure `MoneyMention` finds in any language (which overrides a no), limits it to people with `money.view`, by push, in the app list and in the history, and its picture is then only on short-lived signed addresses. A company may have 20 waiting and write 20 a day. A module that needs to tell people something should still use `Notifier` with its own category.

## Contractor logins

A role marked `'portal' => true` in `config/buildos.php` (today only `contractor`) is an outside login, not a team member. `User::isContractor()` names it, `App\Http\Middleware\ContractorPortalGate` turns it away from every signed-in route outside `contractor-portal.*` and `api/v1/contractor-portal/*`, the team screens never offer or edit it, and it takes no seat on the plan. It is handed out from the contractor's page by whoever holds the role's `managed_by` permission, through the ordinary invitation flow with `contractor_party_id` set. A new module needs nothing for this: the gate refuses a contractor before the module's routes run. `tests/Feature/ContractorPortal/ContractorPortalBoundaryTest` walks every route to prove it.
