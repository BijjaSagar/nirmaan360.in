# Nirmaan360 field app: shared contract

The Laravel API, the offline layer and the screens are built against this file.
If reality forces a change, update this file in the same change.

## 1. Rules that apply everywhere

- Money and quantity are never `double` in Dart or `float` in PHP. On the wire they are strings: quantity scale 3 (`"12.500"`), money scale 2 (`"950.00"`). Dart uses `Decimal` through `Qty` and `Money` in `lib/core/money`. PHP uses `App\Support\Dec`.
- Local figures are previews. The server's figures are final and replace local ones on the next pull.
- Dates are `YYYY-MM-DD`. Timestamps are ISO 8601 with offset. The server timezone is Asia/Kolkata.
- Conflict policy (NFR-03): server wins for masters, last writer wins with audit for field entries, approved records are never merged.
- Every mobile write carries a `client_uuid`. Repeating a write returns the original row and creates nothing.
- Text is never hidden behind an animation. Entrances move content with transforms only; they never fade text in from 0. With `MediaQuery.disableAnimations` every animation shows its final state at once.
- Every tappable control is at least 56dp high. Every icon that stands for an action has a text label.

## 2. API (`/api/v1`, Bearer token from Sanctum)

The base URL is a build-time setting: `--dart-define=API_BASE_URL=...`.

| Environment | `API_BASE_URL` | Notes |
| --- | --- | --- |
| Release default | `https://nirmaan360.in/api/v1` | Used when nothing is passed |
| Laravel Cloud host | `https://nirmaan360-production-vkgebi.laravel.cloud/api/v1` | Same production app, before DNS moves |
| Local development | `http://127.0.0.1:8361/api/v1` | Demo users exist only here |

Production deploys on every push to `main` and has no demo data. Never create test accounts or test records there.

Every route except login needs `auth:sanctum` and `active`. Tenant isolation comes from `BelongsToOrganization`: another organisation's record is a 404. Feature gates match the web (`feature:site` for DPRs and issues, `feature:labour`, `feature:petty_cash`, `feature:measurement`).

Single records and plain lists are wrapped in `data`. Paginated lists use `?page=` and Laravel's page shape: `{data, current_page, last_page, per_page, total, next_page_url, ...}`. An idempotent create returns 201, and 200 when the `client_uuid` was already seen. List rows have the same shape as the matching key in `/sync/pull`.

### Auth

`POST /auth/login` `{email, password, device_name}` returns `{token, user}`. `GET /me` returns `{user}`.

```json
{
  "id": 4, "name": "Sachin More", "email": "engineer@demo.test",
  "role": "engineer", "role_label": "Site Engineer", "locale": "en",
  "organization": { "id": 1, "name": "Sahyadri Infra Pvt Ltd", "features": ["site", "measurement"] },
  "can": { "dpr.view": true, "dpr.create": true, "dpr.approve": false }
}
```

`can` lists exactly: `project.view, boq.view, dpr.view, dpr.create, dpr.approve, issue.view, issue.manage, attendance.view, attendance.manage, worker.view, worker.manage, petty_cash.view, petty_cash.manage, measurement.view, measurement.create, measurement.approve, ra_bill.view, ra_bill.approve`.

### REST endpoints

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /projects` | `project.view` | exists |
| `GET /projects/{project}/boq` | `boq.view` | exists; add `section`, `measured_qty`, `approved_qty` |
| `GET /projects/{project}/measurements?status=` | `measurement.view` | paginated |
| `POST /projects/{project}/measurements`, `PATCH /measurements/{id}`, `POST /measurements/{id}/submit` | `measurement.create` | exist |
| `GET /projects/{project}/dprs` | `dpr.view` | paginated, newest first |
| `POST /projects/{project}/dprs` | `dpr.create` | idempotent on `client_uuid`; 201 first time, 200 on repeat |
| `GET /dprs/{dpr}` | `dpr.view` | with children, plus `steps` from every approval round, oldest first |
| `PATCH /dprs/{dpr}` | `dpr.create` | DRAFT or RETURNED only; 409 when APPROVED; replaces children |
| `POST /dprs/{dpr}/submit` | `dpr.create` | goes through `ApprovalService` |
| `POST /dprs/{dpr}/photos` | `dpr.create` | multipart `photo`, optional `client_uuid`, `caption`, `taken_at` |
| `GET /dpr-photos/{photo}` | `dpr.view` | image bytes |
| `GET /projects/{project}/issues?status=` | `issue.view` | paginated |
| `POST /projects/{project}/issues` | `issue.manage` | idempotent on `client_uuid` |
| `PATCH /issues/{issue}` | `issue.manage` | `status`, `assigned_to`; sets `resolved_at` like the web |
| `GET /workers` | `worker.view` | active workers |
| `POST /workers` | `worker.manage` | `{name, trade, phone, type (default own), contractor_id, daily_wage (default 0), client_uuid}`; idempotent on `client_uuid`; online only in the app |
| `GET /projects/{project}/attendance?date=` | `attendance.view` | `{date, data}`: marks for that date |
| `POST /projects/{project}/attendance` | `attendance.manage` | `{date, marks: [{worker_id, status, ot_hours, client_uuid}]}`; returns `{date, data}` |
| `GET /projects/{project}/petty-cash` | `petty_cash.view` | wallets with balance and `entries` (latest 30) |
| `POST /petty-wallets/{wallet}/entries` | `petty_cash.manage` | idempotent on `client_uuid`; multipart optional `bill` |
| `GET /petty-entries/{entry}/bill` | `petty_cash.view` | file bytes |
| `GET /approvals` | signed in | `{data, waiting_count}`: items this user can act on, and how many other open approvals there are |
| `POST /approvals/{approval}/act` | `canAct` | `{action: approve\|return\|reject, remark, client_uuid}`; repeat with same `client_uuid` returns the same result; returns `{data: item}` |

`GET /approvals` items:

```json
{
  "id": 7, "doc_type": "measurement", "status": "IN_REVIEW",
  "title": "Measurement · 2.1 · 15.000 cum",
  "project": { "id": 1, "code": "KT-B", "name": "Kalyani Tower B" },
  "submitted_by": { "id": 4, "name": "Sachin More" },
  "submitted_at": "2026-11-09T09:12:00+05:30",
  "current_level": 1, "levels": ["qs", "pm"],
  "current_role": "qs", "current_role_label": "Estimator / QS",
  "can_act": true,
  "document": {},
  "steps": [{ "level": 0, "role": "engineer", "action": "submit", "remark": null, "actor": "Sachin More", "acted_at": "..." }]
}
```

`document` by `doc_type`:

- `measurement`: `{id, boq_item: {code, description, unit}, measured_on, location, description, nos, length, breadth, depth, is_deduction, qty, deviation, remarks, balance_qty}`
- `dpr`: `{id, report_date, weather, summary, hindrances, manpower_total, manpower: [{trade, count}], work_items: [{code, description, unit, qty}], photos: [{id}], created_by_name}`
- `ra_bill`: `{id, number, type, period_from, period_to, party_name, gross_amount, deduction_total, gst_amount, net_payable}`

### `GET /sync/pull?since=<server_time of last pull>`

`since` is compared with `>=`, after conversion to the app timezone. Upserts are idempotent, so a repeated row is harmless. `server_time` is taken before any query. A key the user lacks `.view` permission for is returned as an empty list. With no `since`, transactional lists (`dprs`, `issues`, `attendance`, `petty_cash_entries`, `measurements`) cover `config('buildos.sync_window_days')` (default 60) days, and open issues and non-final documents are always included.

```json
{
  "server_time": "2026-11-09T14:27:54+05:30",
  "user": { "...same as /me": true },
  "meta": {
    "trades": [], "units": [], "expense_categories": [], "issue_priorities": [],
    "issue_statuses": [], "attendance_statuses": [], "weather": ["Clear", "Cloudy", "Rain", "Windy", "Hot"],
    "measurement_tolerance_pct": "5"
  },
  "users": [{ "id": 4, "name": "Sachin More", "role": "engineer" }],
  "projects": [{ "id": 1, "code": "KT-B", "name": "", "location": "", "status": "active", "start_date": "", "end_date": "", "measurement_tolerance_pct": "5.00", "updated_at": "" }],
  "sites": [{ "id": 1, "project_id": 1, "name": "", "address": "", "updated_at": "" }],
  "boq_items": [{ "id": 1, "project_id": 1, "section": "Superstructure", "code": "2.1", "description": "", "unit": "cum", "qty": "620.000", "rate": "7450.00", "sort": 3, "measured_qty": "65.000", "approved_qty": "50.000", "balance_qty": "555.000", "updated_at": "" }],
  "boq_balances": [{ "id": 1, "measured_qty": "65.000", "approved_qty": "50.000", "balance_qty": "555.000" }],
  "workers": [{ "id": 1, "name": "", "phone": null, "trade": "Mason", "type": "own", "is_active": true, "updated_at": "" }],
  "wallets": [{ "id": 1, "project_id": 1, "name": "", "user_id": 4, "balance": "12500.00", "updated_at": "" }],
  "dprs": [{ "id": 1, "client_uuid": null, "project_id": 1, "site_id": 1, "report_date": "2026-11-09", "weather": "Clear", "summary": "", "hindrances": "", "status": "DRAFT", "created_by": 4, "created_by_name": "", "manpower": [{ "trade": "Mason", "count": 12 }], "machinery": [{ "name": "", "hours": "2.0" }], "work_items": [{ "boq_item_id": 3, "description": null, "qty": "68.000" }], "photos": [{ "id": 1, "caption": null }], "return_remark": null, "client_updated_at": null, "updated_at": "" }],
  "issues": [{ "id": 1, "client_uuid": null, "project_id": 1, "site_id": null, "title": "", "description": "", "priority": "high", "status": "open", "location_note": "", "assigned_to": 4, "raised_by": 2, "due_date": "", "resolved_at": null, "client_updated_at": null, "updated_at": "" }],
  "attendance": [{ "id": 1, "client_uuid": null, "project_id": 1, "worker_id": 1, "attendance_date": "2026-11-09", "status": "present", "ot_hours": "0.0", "marked_by": 4, "client_updated_at": null, "updated_at": "" }],
  "petty_cash_entries": [{ "id": 1, "client_uuid": null, "petty_cash_wallet_id": 1, "type": "expense", "amount": "450.00", "category": "Fuel", "description": "", "entry_date": "2026-11-09", "created_by": 4, "has_bill": true, "updated_at": "" }],
  "measurements": [{ "id": 1, "client_uuid": null, "project_id": 1, "boq_item_id": 3, "work_order_id": null, "measured_on": "2026-11-08", "description": "", "location": "", "nos": "2.000", "length": "10.000", "breadth": "5.000", "depth": "0.500", "is_deduction": false, "qty": "50.000", "status": "APPROVED", "deviation": false, "remarks": null, "return_remark": null, "created_by": 4, "client_updated_at": null, "updated_at": "" }],
  "approvals": [],
  "deleted": { "projects": [], "boq_items": [], "workers": [], "issues": [], "dprs": [], "measurements": [] }
}
```

- `boq_balances` holds lines whose ledger moved since `since`, because a measurement does not touch `boq_items.updated_at`. With no `since` it is empty, because `boq_items` is then complete. `deleted` is also empty with no `since`. `deleted.measurements` lists drafts deleted on the web. `wallets` is always the full list. `approvals` is always the full current inbox, in the same shape as `GET /approvals`.
- `return_remark` is the remark on the latest `return` step of the current approval.

### `POST /sync/push`

```json
{ "items": [{ "type": "dpr", "client_uuid": "uuid", "project_id": 1, "server_id": null,
              "client_updated_at": "2026-11-09T10:00:00+05:30", "data": {} }] }
```

Returns `{"results": [{"client_uuid": "...", "status": "...", "id": 12, "server_status": "DRAFT", "reason": null, "errors": null}]}` in request order. One bad item never stops the batch. Maximum 200 items. Permission is checked per item.

| `status` | Meaning | App action |
| --- | --- | --- |
| `created` | new row | done |
| `updated` | newer write applied, audited | done |
| `duplicate` | already applied, nothing changed | done |
| `stale` | server row is newer; audited, incoming ignored | done, tell the user in the sync log |
| `conflict` | `reason`: `approved`, `in_review` or `rejected`; audited, nothing changed | needs attention, never auto-merged |
| `error` | validation; `errors` map | needs attention |
| `forbidden` | `reason`: `permission` or `feature` | needs attention |

Types and `data`:

| `type` | Permission | `data` |
| --- | --- | --- |
| `measurement` | `measurement.create` | `boq_item_id, measured_on, description, location, nos, length, breadth, depth, is_deduction, remarks` |
| `measurement_submit` | `measurement.create` | `entry_client_uuid` or `entry_id` |
| `dpr` | `dpr.create` | `site_id, report_date, weather, summary, hindrances, manpower: [{trade, count}], machinery: [{name, hours}], work: [{boq_item_id, description, qty}]` |
| `dpr_submit` | `dpr.create` | `dpr_client_uuid` or `dpr_id` |
| `issue` | `issue.manage` | `title, description, priority, location_note, assigned_to, due_date, site_id` |
| `issue_status` | `issue.manage` | `issue_client_uuid` or `issue_id`, `status` |
| `attendance` | `attendance.manage` | `worker_id, attendance_date, status, ot_hours` |
| `petty_cash` | `petty_cash.manage` | `wallet_id, type, amount, category, description, entry_date` |

Rules for `measurement` and `dpr` (find by `server_id`, else by `client_uuid`):

1. Not found: create as DRAFT, store `client_uuid` and `client_updated_at`, return `created`.
2. Found and APPROVED, IN_REVIEW or REJECTED: `conflict`, with `AuditLog::record('sync.conflict', ...)` holding the incoming payload. Exception: a `client_updated_at` equal to the row's stored `client_updated_at` is a retry of a version already applied, so it is `duplicate`. Otherwise retrying a `dpr` and `dpr_submit` batch whose response was lost would report a false conflict.
3. Found, editable, no `client_updated_at` sent, or equal to the stored one: `duplicate`.
4. Found, editable, `client_updated_at` older than the stored stamp (`client_updated_at`, else `updated_at`): `stale`, with `sync.stale` audit.
5. Otherwise update through the service, store the stamp, and fill `client_uuid` if the row had none: `updated`.

- `*_submit`: already IN_REVIEW or APPROVED is `duplicate`; REJECTED is `conflict`. RETURNED and DRAFT submit through the service. An unknown target is `error`.
- `attendance` is keyed on `(project_id, worker_id, attendance_date)` and follows rules 3 to 5. Same `client_uuid` and same stamp is `duplicate`.
- `petty_cash` is append-only: an existing `client_uuid` is `duplicate`. Wallet must belong to `project_id`.
- `issue` follows rules 1, 3, 4 and 5. `issue_status` is last writer wins against the stored stamp (`client_updated_at`, else `updated_at`), and an equal stamp is `duplicate`. Comparing with `updated_at` alone would mark a status changed offline as `stale` once the issue it follows had been synced. With no stamp, a real status change applies and the same status is `duplicate`.
- Web and REST edits clear `client_updated_at`, so `updated_at` decides against them.
- A row soft-deleted on the server is `duplicate`; `deleted` in the next pull removes it locally. A row found under a different `project_id` is `error`.

### `POST /sync/upload` (multipart)

`type` (`dpr_photo` or `petty_bill`), `client_uuid`, `parent_client_uuid` or `parent_id`, `file`, optional `caption`, `taken_at`. Returns `{"status": "created" | "duplicate", "id": 3}`. Parent not found is 404 (the app retries later). An approved parent is 409, unless this `client_uuid` was already stored, which stays `duplicate`. `dpr_photo` needs `dpr.create` and `petty_bill` needs `petty_cash.manage`, with the matching plan feature, otherwise 403. `created` is HTTP 201 and `duplicate` is 200. For `petty_bill`, `id` is the entry's id, and an entry that already has a bill is `duplicate`.

### Migration needed

Add nullable `client_uuid` with `unique(organization_id, client_uuid)` to `attendance`, `workers`, `dpr_photos` and `approval_steps`. Add `client_updated_at` (timestamp, nullable) to `dprs`, `issues`, `attendance`, `measurement_entries`. Add `taken_at` (timestamp, nullable) to `dpr_photos`. `Attendance` becomes `Auditable`.

### Setup from the app (online only)

An owner can set up a site without the website. These are masters, so they need network and are not queued.

Add `project.manage` and `boq.manage` to the `can` list. Add nullable `client_uuid` with `unique(organization_id, client_uuid)` to `projects`, `boq_items` and `petty_cash_wallets`.

| Method and path | Permission | Body | Returns |
| --- | --- | --- | --- |
| `POST /projects` | `project.manage` | `name` (required), `location`, `start_date`, `end_date`, `contract_value`, `code`, `client_uuid` | `{data}` in the `projects` pull shape |
| `POST /projects/{project}/boq-items` | `boq.manage` | `description`, `unit`, `qty`, `rate` (all required), `code`, `section`, `client_uuid` | `{data}` in the `boq_items` pull shape |
| `POST /projects/{project}/wallets` | `petty_cash.manage` | `name` (required), `user_id`, `client_uuid` | `{data}` in the `wallets` pull shape |

- All three return 201 the first time and 200 for a repeated `client_uuid`, with no second row. Other organisations' records are 404.
- A blank project `code` is generated: up to 4 uppercase initials of the name, with a number added if needed to stay unique in the organisation. Other project fields use the schema defaults.
- A blank BOQ `code` becomes the next number in that project. `section` is a name, found or created in that project. `sort` follows the web rule.
- The plan project limit is a 422 with `errors.plan`, using the web's message.
- A wallet top-up is an ordinary `petty_cash` push item with `type: topup`, so it works offline.

### Site progress and stages

Needs the `site_control` plan feature, and `progress.view` and `progress.create` in the `can` list. The API sends quantities and percentages only, never a rate or an amount.

- The app reads `GET /projects/{project}/progress/summary`, `/floors`, `/progress/items`, `/progress/pending` and `/stages`, and keeps the last answer per project. After a sync that sent something it reads them again; otherwise at most every 10 minutes. `GET /floors/{floor}/progress` is read when a floor is opened. The before finishing check is worked out from `/stages`, so it needs no network.
- These writes are outbox rows posted one at a time to their own endpoints, not through `/sync/push`: `POST /projects/{project}/progress/entries`, `POST /progress/entries/{id}/resolve`, `POST /projects/{project}/stages/cells`, and the photo routes `POST /progress/entries/{id}/photos` and `POST /stage-cells/{id}/photos`.
- Every one carries a `client_uuid`, and a repeat returns the first row. A photo waits behind its parent and takes the parent's server id from the reply.
- Screens lay rows waiting to send over the last answer, so quantities and stages are right before they reach the server. A newer change to a stage replaces one still waiting.
- 422 and 403 go to the Sync Center. 404 says the project is gone when the project has left the phone.

## 3. App layout

Flutter 3.44, feature-first, BLoC (Cubits are fine), Drift, Dio, go_router.

```
lib/
  main.dart, app/                 bootstrap, router, DI (orchestrator)
  core/design/                    tokens, theme, motion, widgets (design agent)
  core/{api,db,sync,money,session,config}/   (data agent)
  features/<name>/data/           repositories (data agent)
  features/<name>/{bloc,view}/    screens (feature agents)
  l10n/generated/                 generated; never edit
l10n/<area>.json                  translation fragments, one per agent
tool/build_l10n.dart              run: dart run tool/build_l10n.dart && flutter gen-l10n
```

Features: `auth, projects, home, dpr, measurement, issues, attendance, petty_cash, approvals, sync_center, settings`.

Translation keys are `<area><Thing>` in lowerCamelCase and live in `l10n/<area>.json` with `en`, `mr` and `hi` side by side. Never edit `lib/l10n/*.arb`. Reuse wording from `lang/mr.json` and `lang/hi.json` where a term already exists.

Generated `*.g.dart` and `lib/l10n/generated` are committed, so a fresh checkout analyzes and tests without code generation.

### Offline model

Field tables use `client_uuid` as primary key, with nullable `server_id`. A row that came from the server with no `client_uuid` gets a stable UUID v5 of `<type>:<server_id>`, and later pushes send that UUID plus `server_id`.

Each user action is one Drift transaction that writes the local row and its outbox row. Outbox statuses: `pending`, `sending`, `done`, `attention`. Order is FIFO with dependencies, so a DPR goes before its photos and its photos go before its `dpr_submit`. Non-file items go in batches of 50.

`client_updated_at` strictly increases per record (`max(now, previous + 1s)`). Repeated attendance taps on one worker and date replace the pending outbox row's payload.

Pull upserts masters unconditionally and applies `deleted`. For a field row with an unsent outbox item, keep local content but take `server_id` and any status of APPROVED or IN_REVIEW. Otherwise the server row replaces local. The pull watermark moves only after a fully applied pull.

Sync runs after each local write (debounced), on connectivity regained, on app resume, on a foreground timer with backoff, and in the background through `workmanager`. Session token, locale and last project survive restarts, and nothing at start-up needs the network.

Approvals are online only, by design: the app shows a clear message when offline.

## 4. Design language

Warm and soft: a light warm page, large white rounded cards, big confident figures, orange progress with diagonal hatching, a floating bottom bar, and rubber-stamp statuses for documents. Fonts: Poppins for headings and big figures, Mukta for body, DM Mono only where columns of figures must align. Devanagari needs generous line height (at least 1.35 for body).

| Role | Hex | Token |
| --- | --- | --- |
| Background | `#EFECEB` | `paper` |
| Light surface | `#E3D6CF` | `peach`, `paper2` |
| Warm beige | `#DCB8A4` | `beige` |
| Card tint | `#E1C9BB` | `goldTint` |
| Primary orange, progress and highlights | `#E1985E` | `haldi`, `amber` |
| Deep orange, active | `#BC5E2F` | `orange` |
| Delayed and urgent, as a fill | `#E63E06` | `accent` |
| Text | `#070706` | `ink` |
| Charcoal, secondary text | `#575250` | `ink2`, `inkLabel` |
| Muted grey, decoration and disabled only | `#9FA2A7`, `#AAAAAA` | `ink3`, `muted` |
| Cards | `#FFFFFF` | `sheet` |

Outdoor contrast: body text at least 7:1, labels at least 4.5:1. Text on orange is dark ink. The deep oranges are below 4.5:1 on light surfaces, so small orange text and icons use `laterite` (`#8F4622`), a darker cut of `#BC5E2F`. Primary actions sit in the lower half of the screen for one-handed use, and every icon that stands for an action has a text label.

Motion must mean something and stay cheap on a low-end Android phone: no blurs, no backdrop filters, no animated opacity on large subtrees, `RepaintBoundary` around anything that animates often, transforms over layout.
