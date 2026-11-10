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

### Project photo

`image_url` is on each project in `GET /projects` and the `projects` rows of the pull, null when there is no photo. It needs the same bearer token, so the app asks for the same path on its own configured server rather than fetching the address as given. The `v=` value changes with the photo. The photo is kept on the phone for offline use, and the first letter of the name shows when there is none. Setting a photo stays on the website.

### Who may see money

Site roles do not have `money.view`. For them the server leaves rates and bill figures as null and drops any wage they send. The app shows quantities only, skips the wage step, hides the progress card weighted by rates, and offers no approval on a bill sent without figures.

### Material control

Needs the `site_control` plan feature, and `material_control.view`, `.challan`, `.consume` and `.count` in the `can` list.

- The app reads `GET /projects/{project}/material-control/check`, `/challans`, `/consumption`, `/stock-checks`, `GET /material-control/suppliers`, and the material, unit and store lists from `GET /projects/{project}/procurement/snapshot`. It keeps the last answer per project, on the same schedule as site progress.
- A delivery, a use and a count are outbox rows posted to `POST /projects/{project}/material-control/challans`, `/consumption` and `/stock-checks` with a `client_uuid`. A repeat returns the first row and moves no stock. A challan photo goes with the fields as multipart field `photo`.
- Screens lay rows waiting to send over the last answer, converting to the base unit with the material's factors.
- A use above the stock is a 422 on `stock` and goes to the Sync Center. The form warns first but does not block, because the phone's figure may be out of date.
- Answering a flag needs a written reason on the website. The API has no route for it.

### Site progress and stages

Needs the `site_control` plan feature, and `progress.view` and `progress.create` in the `can` list. The API sends quantities and percentages only, never a rate or an amount.

- The app reads `GET /projects/{project}/progress/summary`, `/floors`, `/progress/items`, `/progress/pending` and `/stages`, and keeps the last answer per project. After a sync that sent something it reads them again; otherwise at most every 10 minutes. `GET /floors/{floor}/progress` is read when a floor is opened. The before finishing check is worked out from `/stages`, so it needs no network.
- These writes are outbox rows posted one at a time to their own endpoints, not through `/sync/push`: `POST /projects/{project}/progress/entries`, `POST /progress/entries/{id}/resolve`, `POST /projects/{project}/stages/cells`, and the photo routes `POST /progress/entries/{id}/photos` and `POST /stage-cells/{id}/photos`.
- `GET /progress/items` lists `work_orders` on a line that several active contractors share. A quantity on such a line must send `work_order_id`, or the server answers 422. A pending note with no quantity does not need it.
- Every one carries a `client_uuid`, and a repeat returns the first row. A photo waits behind its parent and takes the parent's server id from the reply.
- Screens lay rows waiting to send over the last answer, so quantities and stages are right before they reach the server. A newer change to a stage replaces one still waiting.
- 422 and 403 go to the Sync Center. 404 says the project is gone when the project has left the phone.

### Correcting a progress entry

Needs `progress.create`. An entry is never edited: a correction is a new entry that holds the difference, dated the same day, and the first entry stays.

- The floor screen lists the latest entries on the floor that recorded work, newest first, from `GET /projects/{project}/progress/entries?project_floor_id=`. The list is oldest first, 100 to a page, so the phone reads page 1 for `last_page`, then the last page, and the one before it when the last holds fewer than 30. The rows are kept per project under `floor_entries.<projectId>`, 200 to a floor, and the key goes with the project. A correction row folds into the entry it corrects (`corrects_entry_id`), and the entry's quantity is its own plus its corrections.
- A correction is an outbox row of type `progress_correction`, posted on its own to `POST /progress/entries/{id}/corrections` with `qty_done` (what the entry should have been, not the difference), a required `remark` and the `client_uuid`. A repeat returns the first row. Its group is `correct:<entryId>`, so it holds nothing else back. A newer correction of the same entry replaces one still waiting, and putting the quantity back to what the server counts takes the waiting one back.
- The reply, like the reply to a new entry, is added to the floor's list in the transaction that finishes the outbox row, so an entry sent from the phone can be corrected at once and a correction shows with no refresh.
- Until it is sent, a correction shows in the list and in the item's done figure, worked out as the quantity less what the server counted when it was written (`net_before`).
- Only an entry that recorded work can be corrected, which is why the list leaves out corrections, resolutions and entries with no quantity. The server answers 422 on `qty_done` for a correction of a correction, an entry with no quantity, or a quantity equal to what the entry already counts, and on `remark` when it is blank. The phone checks the last two and a quantity outside 0 to 99,999,999 before queuing; the rest go to the Sync Center. 403 and 404 go there too.

### Photos on issues

Needs the `site` plan feature. `issue.view` reads and `issue.manage` adds. The kind is `before` or `after`.

- The raise-issue form takes up to 10 before photos. The issue detail shows the server's photos from `GET /issues/{issue}/photos` (`id, issue_id, kind, caption, taken_at`) and the pictures from `GET /issue-photos/{id}`, and adds a before or an after photo. Removing a photo stays on the website.
- A photo is an outbox row of type `issue_photo`, posted on its own to `POST /issues/{issue}/photos` as multipart `photo` with `kind`, `client_uuid` and `taken_at`, not through `/sync/push`. A repeat returns the first row.
- The issue goes through `/sync/push`, so a photo takes the issue's server id at the moment it is sent: it reads `issues.server_id` for the photo's `parent_uuid`, and nothing is copied into the photo row. A photo for an issue the server has never seen shares its group, so it waits behind the issue. A photo for an issue the server already holds is its own group, so a failed status change cannot hold it back.
- The server's lists are kept per project under `record_photos.<projectId>` and each picture seen or sent is kept as a file, so photos open with no network. A sent photo joins the list in the same transaction that finishes its outbox row. The key goes when the project does.
- 422, 403 and 404 go to the Sync Center. A 409 (a record locked while the photo waited) goes there too, as `approved` or `in_review`.

### Photos on measurement entries

Needs the `measurement` plan feature. `measurement.view` reads and `measurement.create` adds.

- The entry detail lists `GET /measurements/{measurement}/attachments` (`id, measurement_entry_id, kind, caption, is_image`) and fetches pictures from `GET /measurement-attachments/{id}`. A scanned PDF sketch from the office is listed as a document and is not drawn. The phone adds photos only: kind `photo`, a JPEG, at most 10 on an entry. The details sheet of the entry form takes photos for the row being measured, and each saved row keeps its own.
- A photo is an outbox row of type `measurement_photo`, posted on its own to `POST /measurements/{measurement}/attachments` as multipart `file` (not `photo`) with `kind` and `client_uuid`. It reads the entry's `server_id` when it is sent, as issue photos do. A repeat returns the first row, even on an entry approved since.
- The server refuses a photo with a 409 unless the entry is DRAFT or RETURNED, and the model refuses a change on APPROVED or IN_REVIEW, so the phone offers one only while the entry can still change, and refuses with `RecordLocked` once a submission is waiting to go.
- A photo always shares its entry's group, so a `measurement_submit` queued after it cannot overtake it. The cost is that a photo needing attention holds the submission until a person retries or discards it.
- A 409 that arrives anyway (approved or sent for review from the web while the photo waited) goes to the Sync Center as `approved` or `in_review`, with the photo left in place. Lists, pictures and cleanup are the same as for issue photos.

### Site dashboard and alerts

Needs the `site_control` plan feature. The two parts have their own permissions, and `UserPresenter` now sends both: the dashboard goes by `progress.view`, the alerts by `alerts.view`, and "Scan now" by `alerts.manage`. They are gated on exactly those, with the plan feature, in `GateState.canSeeSiteDashboard`, `canSeeAlerts` and `canScanAlerts`. `progress.view` was a stand-in for the alerts before `alerts.view` reached the app, and is no longer used for them.

- The app reads `GET /projects/{project}/site-dashboard` with `progress.view` and `GET /projects/{project}/alerts?status=open` with `alerts.view`, and keeps the last answer per project under `site.overview.<projectId>`, on the same schedule as site progress. A part the person may not read is not asked for, and is kept as refused. The key goes with the project. `GET /alerts` is not used, because the app works one project at a time.
- The server writes the cards and the alert wording, in the reader's language. A money figure a person may not see is simply not on the card. A money card kept from before someone lost `money.view` is dropped when it is read.
- A 403 or 404 on a part the person has the permission for (the plan feature turned off meanwhile) is still kept as "refused": the screen shows a calm message and Home leaves the row out when both parts are.
- The site page (`/site`) opens for either permission. With both there are two tabs; with one there is only that one, whatever `?tab=` asked for. With neither permission it goes Home. The route does not ask for the plan feature, as it never did: a company without it meets the server's refusal and the calm page, and only Home's row, which does ask, hides it. Home's one row is the alerts row for `alerts.view` (open count and the worst, in words) and a plain "Site overview" row for `progress.view` alone.
- The phone reads the first page of open alerts, the 30 worst, and shows the true total.
- Severity is a word on each alert. Home has one row above the tiles rather than a ninth tile.

Scan now, for `alerts.manage` only, on the alerts tab (`SiteAlertsRepository.scan`, `SiteAlertsCubit.scan`):

- `POST /projects/{project}/alerts/scan`, no body. 200 `{data: {new, open, resolved}}`. It needs the network and writes nothing on the phone but the list it then reads again; it is never queued. Someone without `alerts.manage` is not offered the button, and the repository sends nothing for them.
- The route is `throttle:10,1`. Past ten a minute the server answers **429** with `{message: "Too Many Attempts."}` and a `Retry-After` header. `RateLimited` keeps that text in `serverMessage`, and the page shows it as the server said it ("The server said: ..."), then the wait from `Retry-After` in seconds. The same for a 403 ("This action is unauthorized.") and a 422: the server's words, not ours. A 5xx, a reply the phone cannot read and an unknown failure are only "did not go through"; the server's words for those are not passed on. With no network the page says scanning needs the internet, and nothing is sent.
- Checked against the local server with the app's own client: a project manager's scan answers 200 with the counts, then 429 `Too Many Attempts.` with `Retry-After: 60` once the minute's allowance is used; an engineer, who has `alerts.view` but not `alerts.manage`, gets 403 `This action is unauthorized.`.

### Contractor control

Needs the `site_control` plan feature. `contractor.view` reads, `contractor.request` raises a request, `money.view` sees figures. `ContractorControlPresenter` decides what leaves the server: without `money.view` a contract has `work_pct` and `balance_work_pct` and nothing about money, and the payment list holds only the requests this person raised, with no amounts.

- The app reads `GET /projects/{project}/contractor-control`, `/contractor-payments` (the latest 50) and `GET /work-orders/{id}/contractor-control` for up to 30 open contracts, a few at a time, and keeps them per project under `contractor.overview.<projectId>`. A contract is read again when it is opened. The key goes with the project.
- Figures are one nullable object on each row, so a screen shows them all or none, and a withheld figure can never read as `₹0`. A copy kept from before someone lost `money.view` is stripped when it is read.
- A payment request is an outbox row of type `contractor_payment`, posted on its own to `POST /work-orders/{id}/contractor-payments` with a `client_uuid`, multipart with `bill` when there is a photo. It is safe offline: `raise()` checks no live balance and a bill is optional, a manager sees the figures when deciding, and a repeat returns the first row. The form holds one `client_uuid` for its life.
- `is_advance` is sent as 1 or 0, because the server's boolean rule does not take `true` and `false` from multipart.
- The reply is added to the kept list in the transaction that finishes the outbox row, so a sent request never vanishes before the next read, when someone might raise it again.
- A 422, such as a closed contract, goes to the Sync Center, and the payment row shows the server's own words. 403 and 404 go there too. The form checks the amount above 0 and at most two decimal places, and refuses a date in the future.
- Requests still waiting are laid over the list. The figure someone without `money.view` typed is never shown back, in the list, the Sync Center or the outbox title.
- The bill opens from `GET /contractor-payments/{id}/bill` for `money.view`. A PDF cannot be drawn on the phone, so it says to open it on the website. Approving stays on the approvals screen or the website.

### Contractor portal

A subcontractor can sign in. `GET /me` and the login reply carry `kind` (`contractor` or `staff`), `contractor` (`party_id`, `name`) and `can['contractor_portal.view']`; a reply from an older server has no `kind` and is staff. Needs the `contractor_portal` plan feature. `ContractorPortalGate` refuses a contractor with a 403 everywhere except `/contractor-portal*`, `/me`, sign-out and language, so the app must not make any other call for one.

- A `kind == contractor` login lands on the portal at `/portal` and nowhere else: no project picker, no site tabs, no sync pull or push, no site tiles. The gate sends every other path to the portal, except Settings (language, who is signed in, sign out). A member of staff who opens a `/portal` path goes Home.
- `SyncEngine.syncNow` returns at once for a contractor, so the timer, a resume, the network coming back, the Sync Center and the background task all end there with no call. The saved user (`session.user` with `kind` and `contractor`) is what the background task reads. The sign-in does not pull: it reads the portal instead. `afterPull` never runs.
- The app reads `GET /contractor-portal` (`{contractor, summary, contracts}`), `/payments`, `/bills`, `/work-orders/{id}` (lines, parts, payments, bills) for up to 30 contracts, and `/bills/{id}` (items, deductions) for up to 30 bills, a few at a time. The whole answer is kept as one value under `portal.snapshot`, so every portal page opens offline. Home reads again when the copy is older than 10 minutes and on pull to refresh; a contract or bill is read again when it is opened. Each refresh asks `GET /me` first, so a changed permission, plan or kind reaches a phone that never pulls.
- Everything is read only. A 403 or 404 on the overview, or a login without the permission or plan, keeps `refused` and the page says so calmly. A failed read keeps the last copy. A copy whose `party_id` is not this login's is never shown. Sign-out clears it with the rest of the phone.
- The server's figures are shown as sent. `status` and the `label` text are not used: the page words the comparison itself ("Paid 56% · Work done 47%") in the app's language. Days left are counted on the phone from `deadline`, so a copy kept for a week is still right.

### Price watch

Needs the `price_watch` plan feature. `money.view` reads the board and a material; `money.overview` also reads a project's budget effect. Read only. Every reply carries `meta.live_feed: false`: these are prices a person wrote down, each with its date. The app never calls one live or a market price, in any language, and a test reads the strings to keep it so.

- The app reads `GET /price-watch` (`data` rows with `current`, `previous`, `change_pct`, `trend`, `count`; `meta.as_of`, `meta.stale_after_days`), `GET /price-watch/materials/{id}` (the row plus every `prices` entry with `source` and `noted_by`), and with `money.overview` `GET /projects/{project}/price-watch` (rows with `boq_rate`, `boq_qty`, `difference`, `above_boq_pct`, `need`, `received`, `remaining`, `impact`, `why`; and a `summary`).
- The board and each material's list are kept as one value under `price_watch.board`; a project's budget effect under `price_watch.project.<projectId>`, which goes with the project. Both are read through `afterPull`, at most every 10 minutes, and when the screen opens or is pulled. A material's list is read again only when its board row says a price was added or changed.
- The phone does not ask without `money.view` and the plan feature, nor for the budget without `money.overview`. A copy kept from before a permission was lost is not shown and is deleted on the next sync; a copy read for a contractor login is never shown. Nothing about prices appears for anyone without `money.view`: no tile, no route (it goes Home), no strings.
- Out of date is worked out on the phone (`priced_on` plus `stale_after_days` against today), so a copy kept for a month does not claim to be fresh. A date is always written as a date, never as "Yesterday".
- A 403 or 404 is kept as "refused" and the screen says so calmly. A failed read keeps the last copy. Impact is `(latest recorded price - BOQ rate) x quantity still to buy` and is shown in words ("more" or "less"), never as a bare sign. A material with no effect says why (`why`).
- The way in is a labelled tile on the Material page's top bar, only for `money.view` on a plan that has it. Home has no room for a third line.

### Inspections and checklists

Needs the `qhs` plan feature and `checklist.fill` in the `can` list. `checklist.view`, which the server asks for on every read, does not reach the app, so the phone gates on `checklist.fill` alone (a 403 on a read is kept as "refused" and the screen says so calmly). Every role that fills a checklist also sees them. A company on the offline plan has no `qhs`, and the gate also keeps the screens away from any phone that keeps its data local.

The server's rules, from `ChecklistService` and `InspectionApiTest`:

- An item's result is `pending`, `pass`, `fail` or `na` ("Not applicable"). An inspection is `in_progress`, `passed` or `failed`. `pending` is the start state and cannot be sent as a result by the phone.
- A `fail` needs a written `remark` (at most 255 characters), or the call is a 422 on `results`. There is no photo route on the inspection API, so a failure has words and no picture.
- Completing needs a result on every item (`na` counts), or 422 on `items`. Any `fail` makes the inspection `failed`, otherwise `passed`.
- A completed inspection is locked. `PATCH /inspections/{id}` and `POST /inspections/{id}/complete` on one both answer 409, "A completed inspection cannot be changed."
- `POST /projects/{project}/inspections` takes `checklist_template_id`, optional `title`, `location` (160), `site_id` and `inspected_on` (not in the future), the `results` as `[{position, result, remark}]` by item position starting at 1, and `complete: true`, with a `client_uuid`. It is one transaction: a refusal stores nothing. A repeated `client_uuid` returns the first inspection with 200 and applies nothing. Items are copied from the checklist when the call arrives, so a position means whatever the checklist says at that moment.

What the phone does with that:

- It reads `GET /checklist-templates`, `GET /projects/{project}/inspections` (the first page, 25, newest first) and `GET /inspections/{id}` for the latest 15, and keeps them per project under `inspections.<projectId>`, on the same schedule as site progress. A kept inspection is read again only when its `updated_at` changes. The key goes with the project, and is dropped on the next sync when someone loses the permission or the plan.
- An inspection is filled in on the phone as a draft under `inspection.drafts.<projectId>`, written at every mark, so it survives the app closing. A draft is work the server has never seen, so sign-out counts it as unsent.
- Finishing queues one outbox row of type `inspection`, posted on its own to `POST /projects/{project}/inspections` with every result and `complete: true`, and removes the draft in the same transaction. This is the only safe way to queue an inspection offline. The pieces are not: `complete` has no `client_uuid`, and one whose reply was lost is answered 409 on the retry because the inspection is by then locked. So the phone never queues a `PATCH` or a separate `complete`.
- Before it sends, the engine reads `GET /checklist-templates` and compares the checklist's items with the copy the draft was made from. A checklist that was edited or removed in between goes to the Sync Center with the reason and nothing is sent, because a pass could otherwise be recorded against a different check and a completed inspection cannot be corrected.
- The reply is added to the kept list in the transaction that finishes the outbox row, with its items. A finished inspection waiting to send is laid over the list, marked "Not sent yet", and opens from its uuid. Its status there is a preview.
- An inspection started on the website and left `in_progress` is shown read only, with a note to finish it there. The phone offers no way to continue one, for the reason above.
- 422, 403 and 404 go to the Sync Center, in the server's own words for a 422.
- Item states are shown in words with a mark beside them (Pass, Fail, Not applicable, No result yet), never by colour alone.

### Machine logs

Needs the `assets` plan feature and `asset.log` in the `can` list. `asset.view`, which the server asks for on the two reads, does not reach the app, so the phone gates on `asset.log` alone and treats a 403 or 404 on a read as "refused", with a calm page. Every role that logs a machine also sees them. A company on the offline plan has no `assets`, and the gate also keeps the screens away from any phone that keeps its data local. Nothing about a rate, a hire term or a cost is read, kept or shown: `MachineLogPresenter` sends none, and the hire-bill flag `billed` is not used either.

The server's rules, from `PlantLogService::logRules` and `record`, and `MachineLogApiTest`:

- `POST /machine-logs` takes `asset_id` and `log_date` (not in the future), and optional `project_id`, `hours` (0 to 24), `km` (0 to 99,999), `breakdown_hours` (0 to 24), `opening_reading` and `closing_reading` (0 to 99,999,999), `operator` (80), `remarks` (255) and `client_uuid`. Quantities are strings, hours `"7.5"`, readings and km `"108.500"`.
- Something must be entered: `hours`, `km`, `breakdown_hours`, or both readings. A reading pair gives `hours` on a machine whose `meter_unit` is `hours`, and `km` on one whose `meter_unit` is `km`. A machine with no meter unit gets nothing from its readings. Otherwise 422 on `hours`, "Enter the hours or km worked, or the hours lost to breakdown." A closing reading below the opening one is a 422 on `closing_reading`.
- One machine cannot pass 24 hours of `hours` on one day, counting every log of that day: 422 on `hours`. A day a hire bill covers, and a retired machine, are refused on `log_date` and `asset_id`.
- A repeated `client_uuid` returns the first log with 200 and stores nothing. 201 the first time. The API has no way to edit or delete a log.

What the phone does with that:

- It reads `GET /assets` (up to five pages of 100, retired machines are not listed) and `GET /machine-logs?project_id=` (the latest 50), and keeps them per project under `machines.<projectId>`, on the same schedule as site progress. The key goes with the project, and is dropped on the next sync when someone loses the permission or the plan. A row that does not parse never stops the sync: a figure that is not a number is left out, a machine with no id is skipped.
- A log is an outbox row of type `machine_log`, posted on its own to `POST /machine-logs` with a `client_uuid` and the project's id. It is safe to write offline: a repeat stores nothing. The form holds one `client_uuid` for its life.
- Hours are `Hours`, one decimal, like overtime. A figure typed with more places is refused, never rounded. A server figure shows to one decimal. Readings and km are `Qty`.
- The form checks what it can from the last copy: a machine, a day not in the future, hours and breakdown from 0 to 24, km and readings in range, the closing reading not below the opening one, and something entered, with a reading pair counting only on a machine whose meter counts hours or km. It says how many hours the phone knows are already logged for that machine that day, and does not block: another phone may have logged more or less, and the server decides.
- What the server can still refuse when the log arrives (the 24 hours, a hire bill, a machine retired meanwhile, 403) goes to the Sync Center in the server's own words.
- The reply is added to the kept list in the transaction that finishes the outbox row. Logs waiting to send are laid over the list, marked "Not sent yet", and counted toward the day's hours the phone knows of.
- Home has one "Site tools" row for inspections and the machine log together, and it names only the tools open to that person. It is hidden when neither is.

### Goods receipts and store issues

Inside the Material page, not a tile of their own. Needs the `procurement` plan feature and `procurement.stock` in the `can` list; `procurement.view`, which the server asks for on the reads, does not reach the app, and every role that has `procurement.stock` (pm, engineer, store) also has it and `material_control.view`. The page itself still needs `material_control.view`, so a custom role with `procurement.stock` alone has no way in. A contractor login and a phone on the offline plan are never offered either (`GateState.canStockProcure`).

The server's rules, from `GrnService`, `MaterialIssueService`, `ProcurementSyncService` and `ProcurementApiTest`:

- `GET /projects/{project}/procurement/snapshot` gives the open purchase orders (`ISSUED` or `PARTIAL`) with, per line, `qty`, `unit_id`, `base_qty`, `received_base_qty` and `remaining_base_qty`, the stores, and `stock` per store and material in the base unit. A receipt that fills an order takes it off the list.
- `POST /projects/{project}/grns` takes `purchase_order_id`, `grn_date` (not in the future), optional `store_id` (the order's store when left out), `challan_no` (60), `vehicle_no` (30), `remarks` (2000) and `items: [{purchase_order_item_id, qty, unit_id, rejected_qty}]`. **`qty` is what was accepted**: above 0 with at most three decimals, it is what goes into stock and what counts against the order. With what came before it cannot pass the ordered quantity, counted in the base unit across units: 422 on `over_receipt`, "Only 60.000 bag of Cement is still due on this purchase order." **`rejected_qty`** is recorded beside it and is neither stock nor received, so the order still owes it. It is not converted: it is in the same unit as the line. A line of nothing accepted is a 422 on `items.N.qty`, so a delivery that was all turned away cannot be recorded from the phone. A part delivery is normal: the order becomes `PARTIAL`, then `RECEIVED` when every line is in. An order that is not open is a 422 on `purchase_order`.
- `POST /projects/{project}/material-issues` takes `store_id` (required, so a project with no store cannot issue), `issue_date`, `issued_to` (160) and `items: [{material_id, qty, unit_id, boq_item_id, activity}]`. Each line needs a BOQ item or an `activity` (160), or both. More than the store holds is a 422 on `stock`, "Only 105.000 bag of Cement is available in Site store." The server stores a use from material control as an issue with one line, so `GET /material-issues` and `.../material-control/consumption` return the same rows, and the app reads the second.
- A repeated `client_uuid` returns the first row with 200 and moves no stock, before any field is checked, so a reply lost after the order filled still comes back. A uuid used on another project is a 422 on `client_uuid`.

Why the single endpoints and not `POST /procurement/push`:

- Both repeat safely. The difference is in a fault. `push` catches any exception for an item, reports it as `status: error` with "This record could not be saved", and answers 200. The app would take that for a refusal and hand a lock wait or a deadlock, which a second try clears, to a person. The single endpoint answers 500, and the outbox sends the same row again on its own.
- The services take a deadlock with `DB::transaction(..., 3)`. Under `push` they run inside the per-item transaction, where that retry is nested and does not retry the way it does when the call is the outermost.
- A 422, 403 and 404 land in the same direct-post path as every other write, and the Sync Center shows the server's own words.

What the phone does with that:

- The Material page keeps the open orders, the store stock and the latest receipts in the same value as the rest of material (`material.overview.<projectId>`), read on the same schedule. Only people who may receive and issue get them, and they are written only after `ProcurementCopy` has kept the listed quantity fields: **a rate, an amount or a tax figure is never read, kept or drawn**, even when a server sends one. A copy kept for someone who lost the permission is not refreshed with orders.
- A receipt is an outbox row of type `goods_receipt`, an issue of type `material_issue`. Each is posted on its own to its endpoint with one `client_uuid` for the whole document, and a repeat returns the first. The `local` part of a receipt (the order's number and each line's material, so a waiting receipt reads right after the order has closed) is removed before it is sent.
- **Both can be queued offline.** Stock and the order's balance are checked by the server when the document arrives, and it is the only judge, so the phone checks what it can from the last copy and sends the rest. The receipt form refuses an accepted quantity above what is still due (a stale copy can only be too generous about what others took, not about what is left, so this is a block), four decimals, a line with a turned-away quantity and nothing accepted, and a receipt of nothing. The issue form warns about more than the store holds and does not block, because the phone's figure may be out of date. What the server can still refuse (another phone took the balance, the stock went) is a 422 in the Sync Center in its own words.
- Waiting documents are laid over the copy: the order is due less, the store holds more or less, the material check moves, and the document is listed as "Not sent yet". A document the copy already lists by `client_uuid` is not laid over twice. The reply is added to the copy in the transaction that finishes the outbox row (`MaterialCache`), including the order's balance and the stock, so nothing jumps back before the next read.
- Units: the line is typed in its order unit by default, with chips for the material's other units. The still-due figure is shown in the unit being typed.
- 422, 403 and 404 go to the Sync Center. A 500 is sent again.

### Attendance by QR card

On the attendance screen, as a "Scan card" button under the muster. Needs `attendance.manage` in the `can` list and the `labour` plan feature (`GateState.canCaptureAttendance`, `AttendanceRepository.canCapture`). A contractor login and a phone on the offline plan are never offered it, and the route sends them back to the muster or Home.

The server's rules, from `QrAttendanceService`, `WorkerCardService` and `QrAttendanceTest`:

- `POST /projects/{project}/attendance/scan` takes `code` (100) and an optional `client_uuid`. It answers `{already_marked, worker, attendance}` with no `data` wrapper: 201 for a new mark, 200 when nothing changed. A card is `N360W-<worker id>-<card version>-<20 hex>`, signed with the server's app key, so only the server can check it. A code that is not a card of this company, a replaced card ("This card was replaced by a newer one") and an inactive worker are 422 on `code`. A worker already marked present on another project today is a 422 on `code` too.
- **The date is the server's today, taken when the scan arrives. The call has no date.** A scan never changes a mark someone already made, except `absent`, which it turns to present. The mark is stored with `method: qr`. A repeated `client_uuid` returns the first mark with `already_marked: true`, even if the card in the repeat is another one.

What the phone does with that:

- It reads only the shape of the code (`WorkerCard`), to turn away what is not a card without a call and to show whose it is from its own workers. It never treats a code as proven.
- **Decision: a scan can be queued offline, but only for the day it was made.** With a network the call is made at once and its answer is shown: "Ramesh marked present", "already marked", or the server's own refusal, which is never queued because the person is standing there. Without a network, or when the reply does not come, the scan is kept as an outbox row of type `attendance_scan` under the same `client_uuid`, so a reply lost after the server marked the worker comes back as "already marked" and makes no second mark. It is posted on its own to the scan endpoint and finishes as `duplicate` when the server says it was already marked. Because the server dates a scan by the day it receives it, a kept scan that is still waiting after midnight (India time) is not sent: it goes to the Sync Center with a note to mark that day by hand (`SyncEngine._checkScanIsToday`). Sending it would put a worker, and a day's wage, on the wrong day. The scan page says so when it saves one.
- A mark already on the phone for today (not absent) is answered at once, with no call. A worker whose scan is waiting reads as present on the muster until the server's mark arrives, unless someone has marked them otherwise since.
- The card text is the only thing kept in the outbox row that identifies a card, and it is never shown or used as a title. The same card in front of the camera for four seconds is one scan.
- A 403 or 404 is shown at once; a 422 on a kept scan (a card replaced meanwhile) and a 500 are as for any direct row: the first goes to the Sync Center, the second is sent again.
- Camera: `mobile_scanner` (BSD-3-Clause), QR codes only. iOS uses the Vision framework and needs iOS 12, the app builds for 15. Android needs minSdk 23, the app has 26. A camera that is off or fails shows a calm page and the muster still works by hand.

### Attendance by selfie

Beside "Scan card" on the attendance screen. The same gate: `attendance.manage` and the `labour` plan feature (`GateState.canCaptureAttendance`, `SelfieRepository.isOpenToUser`), never a contractor login or the offline plan, whose route goes Home. A selfie marks a worker present from a photo taken inside the site's geofence.

The server's rules, from `SelfieAttendanceApiController`, `SelfieAttendanceService` and `SelfieAttendanceTest`:

- `GET /attendance/selfie-consent` gives `{data: {version, text}}`: `selfie-v1` and the note, in the user's language. `POST /projects/{project}/attendance/selfies` is multipart: `site_id` (a site of the project), `worker_id`, `lat` and `lng`, optional `accuracy_m`, `selfie` (the file: jpg, png or webp, at most 8 MB), `consent` (must be accepted), optional `captured_at` and `client_uuid`. The reply is `{data: {id, client_uuid, worker_id, site_id, attendance_date, distance_m, radius_m, consent_version}, attendance}`, 201 the first time and 200 for a repeated `client_uuid`, which is checked before anything else.
- The photo's place is measured against the site's `lat`, `lng` and `geofence_radius_m`. **None of those three is in `SitePresenter` or the pull**, so the phone cannot say "you are 340 m away" before it sends, or show the radius. The answer always comes from the server: a refusal reads "This location is 340 m from Tower A. Attendance needs to be within 200 m." (422 on `lat`), and a success carries `distance_m` and `radius_m`. A reading with `accuracy_m` above the radius is refused as too rough. A site with no map position is a 422 on `site_id`. A worker with a photo for that day already is a 422 on `worker_id`, until it is deleted on the website.
- **The server keeps its own `consent_version` and note with each photo**, whatever the phone showed: `consent_version` is not a field it reads. The phone sends the version it showed anyway, and checks it (below).
- The mark is dated by `captured_at`, not by when the photo arrives, and `captured_at` may be at most 7 days old and 10 minutes ahead. An existing half day or overtime stays as it is; absent becomes present. The mark carries `method: selfie`.
- `GET /attendance-selfies/{id}/photo` returns the picture. The API has no list of selfies and an attendance row carries no selfie id, so there is nothing for the phone to open; it is not used.

What the phone does with that:

- **Consent first.** Before the first photo the note is shown as the server sent it, with its version, and the person who took it says "The worker agrees". That is remembered per signed-in person and per version (`selfie.consent.accepted`), so a new version asks again. The note is kept (`selfie.consent`) so it can be shown with no network, but it has to have been loaded once: with no copy and no network the page says so and offers nothing else. It can be read again from the form.
- **Location only when the photo is taken.** The location is read once, right after the photo, with "when in use" permission only (`SelfieLocator`; `geolocator`). Nothing asks before, nothing follows the phone, nothing runs in the background, and no always-on or background permission is declared. A reading from a mock-location app is not trusted. If there is no location (off, refused, refused for good, timed out) the photo stays on screen with the reason and a button to take it again, and it cannot be sent. The place is sent as text with seven decimal places, so nothing is rounded on the phone.
- **Decision: a photo can be queued offline.** The server dates the mark by `captured_at` and accepts a photo up to a week old, which is its own statement that "a phone can be offline for up to a week before it syncs". With a network the photo is posted at once and the answer is shown: marked, with how far from the centre and how much is allowed, or the server's refusal, which stays on the form so the person can move and try again and is never queued. Without a network, or when the reply does not come, the photo is copied into app storage and kept as an outbox row of type `attendance_selfie` under the same `client_uuid`, posted on its own as multipart with file field `selfie`. The file is deleted only after the server has it. A reply lost after the server stored the photo comes back as the first row and makes no second photo or mark. The worker reads as present on the muster, for the day the photo was taken, while it waits.
- **Before a kept photo is sent the phone reads the consent note again** and compares versions. If the server's note has changed since the photo was taken, nothing is sent: the server would record the worker as having agreed to the new note. The photo goes to the Sync Center to be taken again.
- What the server can still refuse when a kept photo arrives (too far from the site, too rough a reading, a photo for that worker and day already there, older than 7 days) is a 422 in the Sync Center in its own words. A 500 is sent again.
- Platform: `geolocator` (MIT) 14.0.1, iOS 11 or later, Android with Google Play services location, minSdk from Flutter. The Info.plist has `NSLocationWhenInUseUsageDescription` only, and the manifest has `ACCESS_FINE_LOCATION` and `ACCESS_COARSE_LOCATION` only. The package references the always-authorization call, so a build for TestFlight should set `BYPASS_PERMISSION_LOCATION_ALWAYS=1`, or App Store Connect warns about a missing "always" purpose string (ITMS-90683).

### Push notifications

Client side only. Everything is in `lib/core/push/`, `lib/app/push_navigator.dart` and `lib/features/notifications/`.

**Settings come from the build, not from the repo.** `FirebaseSettings` reads `--dart-define-from-file`. `dart run tool/firebase_defines.dart --android <google-services.json> --ios <GoogleService-Info.plist>` reads the two console files and writes the git-ignored `firebase.defines.json` (keys `FIREBASE_PROJECT_ID`, `FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_STORAGE_BUCKET`, `FIREBASE_ANDROID_API_KEY`, `FIREBASE_ANDROID_APP_ID`, `FIREBASE_IOS_API_KEY`, `FIREBASE_IOS_APP_ID`, `FIREBASE_IOS_BUNDLE_ID`). It prints no value. Then `flutter build ... --dart-define-from-file=firebase.defines.json`. Neither Gradle nor the Xcode project references a Google file, so a clone without them builds, and a build without the defines runs with no push at all: `FirebasePushMessaging.isConfigured` is false, Firebase is never started, and Settings shows no notifications row. `firebase_core` 5 and `firebase_messaging` 17 start from Dart (`Firebase.initializeApp(options:)`); the iOS plugin handles a launch with no `GoogleService-Info.plist`, and the Android SDK shows a notification in a cold process with no default `FirebaseApp` (it logs and skips its analytics instead). Both packages are BSD-3-Clause. Never commit `firebase.defines.json`, `google-services.json` or `GoogleService-Info.plist` (`mobile/.gitignore` covers all three).

**Registration.** `POST /devices` `{token, platform: android|ios, app_version, locale: en|mr|hi, previous_token?, device_name}` and `DELETE /devices` `{token}` (`NotifyApiController`, live on production from main 2756b5b) take the FCM registration token itself. A contractor login calls the same two under `/contractor-portal/devices`, the only part of the API its gate lets through; `ApiDeviceRegistry` picks the prefix from the session. `app_version` is `AppInfo.label`; `locale` is the language the app shows (the choice on this phone, then the account's, then the phone's), which is the language the server writes push text in. The reply's `push_connected` says whether the server can send yet; the phone registers either way. `firebase_messaging` 17 marks `getToken`, `onTokenRefresh` and `deleteToken` deprecated for a Firebase installation id (`register()`, `onRegistered`); the server's `FcmGateway` sends to a registration token, so the app keeps the token calls until the server says otherwise.

**When.** Push is for anyone signed in on a company that syncs (`AppServices._mayGetPush`): staff, and a contractor login for its own payments and bills. Never the offline plan, never before sign-in. Firebase auto-init is off in the manifest and `Info.plist`, so until then Firebase learns nothing about the phone. `PushService.setEligible` follows the session. With the phone's permission not asked, `PushState.askFirst` makes `PushPromptHost` (in `HomeShell`, and on the portal's home for a contractor) show `PushPromptSheet` 1.5 seconds after the page appears: a short explanation with "Turn on alerts" and "Not now", worded for a contractor when it is one. `MetaKey.pushExplained` records the answer on the first closing of the sheet and survives a sign-out, so it is asked once per install. Only "Turn on alerts" calls the phone's own question (`requestPermission`; Android 13 `POST_NOTIFICATIONS` is in the manifest). Settings has the row that asks again (`PushSettingsTile`); a phone that refuses for good shows how to allow it in the phone's Settings. A phone that already allows notifications is switched on without any question.

**Token.** Switching on calls `setAutoInitEnabled(true)`, reads the token and registers it. The server asks for a registration after sign-in, on every start of the app and on every new token, and the app sends one each time: a new `PushService` run sends the token once, a refreshed token is sent with `previous_token` set to the one it replaces (the server retires that one in the same call, so there is no separate `DELETE`), and a change of language (`MetaKey.locale`) sends it again with the new `locale`. A token the server did not take (offline, 5xx, 429) is kept and sent again when the network returns and after every sync (`PushService.retryPending`); a 401 or 422 is not retried. Signing out calls `PushService.forgetThisPhone` through `AuthRepository.beforeSignOut`, before `POST /auth/logout`: `DELETE /devices`, `deleteToken`, `setAutoInitEnabled(false)`, each best effort with a 4 second wait, so signing out still works offline. A token the server could not be told about is dropped by the server when Firebase answers `UNREGISTERED`.

**The person's switches.** `GET` and `PUT /notification-preferences` (under `/contractor-portal` for a contractor) are the screen at `/settings/notifications` (`NotificationSettingsScreen`), opened from the push row in Settings once push is on. It is online only and reads `{in_app?, email?, whatsapp?, push: {enabled, connected, categories: [{key, label, hint, enabled}], quiet_hours: {enabled, from, until, timezone}}}`. It shows the main push switch, one switch per category the server offers (`alerts`, `approvals`, `payments`, `material`, `issues`, or `contractor`, and `announcements` for everyone; the app's own words in en, mr and hi, the server's for a key it does not know), quiet hours with their times when on (`HH:MM` on the company's clock; critical pushes still go at once), and email and WhatsApp only when the office has connected them. Each change is sent alone (`{"categories": {"material": false}}`, `{"quiet_hours": {"from": "23:00"}}`, `{"push": false}`), shows at once, and is undone with a note if the server does not take it; the switches wait while one is on its way. The gate lets the page open wherever Settings opens (`Routes.isSettings`), a contractor login included.

**What arrives.** A message while the app is open shows as a short note (`insertPaperToast`) and starts a sync. In the background the phone shows the notification (the server sends `notification` and `data`); `firebaseBackgroundHandler` only makes sure Firebase is up and never opens the database. Android posts to the server's two channels, made in `MainActivity` on every start with names in en, mr and hi: `critical` (high importance, for critical alerts) and `updates` (default importance, everything else, and the manifest's `default_notification_channel_id`). The single `n360_alerts` channel of build 13 is deleted. iOS shows a critical push as `time-sensitive` only if the app holds that entitlement, which it does not yet; it then shows as usual. A tap, from the background or from a closed app (`getInitialMessage`), goes to `PushNavigator`, which waits until the gate is ready, drops the tap when nobody is signed in, and opens the target from Home (from the portal for a contractor).

**Payload the app reads** (`PushTarget.parse`, from `app/Services/Notify/PushTarget.php`; FCM `data` values are strings; anything else is ignored, never an error):

| Key | Meaning |
| --- | --- |
| `type` | `approval`, `contractor_payment`, `material_flag`, `delivery_challan`, `issue`, `alert`, `portal_payment`, `portal_bill`, `announcement`; `dpr` (or `report`) and `measurement` too. `test` and `link` open nothing |
| `id` | The server id of that record. Absent or not a whole number above zero: the list opens instead |
| `project_id` | Optional. Selects that project first, if the person has it |
| `status` | `waiting` means it needs this person; `approved`, `returned`, `rejected` mean a decision was made |
| `approval_id`, `doc_type`, `doc_id`, `work_order_id` | The approval to act on, the document a decision was about, the contract a payment belongs to |
| `event` | Used only when `type` is missing: its first word (`approval.waiting`, `issue.assigned`, `alert.<kind>`) names the kind, with no id |

| Target | Opens | Otherwise |
| --- | --- | --- |
| `approval`, waiting | `/approvals/{id}` | `/approvals` |
| `approval`, decided | the document: `/dpr/{uuid}` or `/measurements/{uuid}` of the row with `doc_id` as its `serverId` | that list; Home for a bill or note, which this phone does not show |
| `contractor_payment`, waiting with `approval_id` | `/approvals/{approval_id}` | `/contractors/{work_order_id}`, or `/contractors` |
| `material_flag`, `delivery_challan` | `/material` | |
| `issue` + id | `/issues/{uuid}` | `/issues` |
| `alert` | `/site?tab=alerts` | |
| `portal_payment` | `/portal/payments` | |
| `portal_bill` + id | `/portal/bills/{id}` | `/portal/bills` |
| `dpr`, `measurement` + id | `/dpr/{uuid}`, `/measurements/{uuid}` | `/dpr`, `/measurements` |
| `announcement` + id | `/settings/announcements?id={id}`, that one marked; staff and contractor logins alike | `/settings/announcements` |

Every path goes through `AppGate.redirect`, the router's own gate: whoever cannot open it lands on Home, or on the portal for a contractor login. Unknown or malformed data lands there too.

### Announcements (broadcasts and reminders)

The office writes these on the website: platform staff under Admin > Announcements for every company, one company, some roles or one project's people; a company's own admin (`settings.manage`) under Account > Announcements for their company. Each goes at once, at a set time, or every day or week at a set time as a reminder, on the India clock. It is an ordinary push of `type: announcement`, `category: announcements`, `event: announcement.sent` (or `announcement.reminder`), `repeats` `"1"` or `"0"`, so the person's main switch, their "Announcements and reminders" switch and their quiet hours all apply, and each phone gets the text in the language it registered with (English when the office wrote none in that language).

**The list.** `GET /announcements?page=&locale=en|mr|hi` (`/contractor-portal/announcements` for a contractor login, `AnnouncementsRepository` picks the prefix) answers Laravel's page shape, 20 a page: `{data: [{id, title, body, image_url, from, repeats, sent_at, first_sent_at}], current_page, last_page, ...}`. It holds only what was sent to the person signed in, including the ones their switches kept off the phone, newest `sent_at` first; a reminder is one row whose `sent_at` moves each time. `locale` is the language the app shows; the server falls back to the account's. `from` is the company's name, or Nirmaan360 for the platform's own. The screen is `/settings/announcements` (`AnnouncementsScreen`), opened from the Announcements row in Settings (never on the offline plan) and from a tapped push. It is a Settings page, so the gate opens it for staff, for a contractor login and with no project chosen. Online only: offline it says so and offers to try again; pull to refresh reads the first page again, and "Show older" reads the next. A refresh or an older page that fails keeps the list on screen and says so in a short note; an older page that arrives after a refresh is dropped. A message with a rupee amount is only ever sent to, and listed for, people who hold `money.view`.

**Pictures.** `image_url` is a public `https` address on the API's own host (`/announcement-images/{40-letter token}`), JPG or PNG up to 1 MB. The app loads it with no token, at 2:1, and shows a plain box if it does not load. The push carries it as `notification.image` and `android.notification.image`, which Android shows itself, and as `apns.fcm_options.image` with `aps.mutable-content: 1`, which wakes the iOS Notification Service Extension (`ios/NotificationService`, bundle id `in.nirmaan360.app.NotificationService`, its version and build number from `pubspec.yaml` through Flutter's xcconfig). It downloads the picture and attaches it, and shows the text alone when the download fails or iOS's time runs out; it uses no Firebase code. A simulator cannot receive FCM, so `tool/check_notification_service.sh` compiles the extension with `ios/NotificationServiceTests/main.swift` for the Mac and runs it against stubbed downloads: the address read from `fcm_options.image`, http refused, and the notification handed back exactly once with or without the picture, on a 404 and when time runs out. The extension is embedded in Runner before Flutter's Thin Binary phase, signed automatically with team `BC52V856K4`, and needs no change to `ExportOptions.plist`: archive and export go through the App Store Connect API key as for the app, which creates its App ID and profile the first time.

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

## Offline plan (`plan: offline`)

One person, one phone, no server copy. Features: site, measurement, labour, petty_cash. Backend: `routes/api_modules/offline_plan.php`, `config/modules/offline_plan.php`, `app/Services/OfflinePlan/*`.

The app asks `GET offline/status` right after sign-in, on start, and at most every 6 hours while online (hourly for a phone on the plan). A company with `syncs: true` stores nothing and behaves as before; a failure of this call is ignored.

| Call | Success | Refusals the app reads |
| --- | --- | --- |
| `GET offline/status` | `{plan, offline, syncs, max_devices, active_devices, licence_days, signing_key}` | |
| `POST offline/devices/activate` | 201 or 200 `{licence, token, signing_key, max_devices, active_devices}` | 409 `device_limit` with `manage_url`, 403 `not_offline_plan` |
| `POST offline/devices/refresh` | 200 same shape | 410 `licence_revoked` with `manage_url`, 404 `licence_missing`, 403 `not_offline_plan` |
| `POST offline/devices/release` | 200 `{status, licence}` | 404 `licence_missing` |
| Anything else | | 403 `{code: "offline_plan", upgrade_url}` |

Token: `v1.<b64url JSON>.<b64url Ed25519 signature over the decoded payload bytes>`; claims `lic, org, usr, dev, plan, features, max_devices, iat, exp`. Verified on the phone with `DartEd25519` against the `signing_key` the same reply carried. A token whose `dev` is another phone is refused.

State is one JSON value under `MetaKey.offlinePlan`, read by the sync engine at the start of every run, so the background task stops too. Modes: `local`, `asking` (the server stopped saying offline), `declined`, `uploading`. Dio also refuses to send anything but `/auth/*`, `/me` and `/offline/*`; `offline_plan` from the server is `OfflinePlanRefused`, never a row-level failure.

Records made on the phone have negative ids and `MetaKey.offlineRecord(kind, id)` beside them: the `client_uuid` the upload uses and any detail with no column (`contract_value`, `daily_wage`). No schema change. A 401 or a sign-out on this plan clears only the sign-in; a different person is refused with `DifferentUserFailure`. The licence ending stops new entries and edits, and nothing else: see "When the licence ends" below.

Upload needs a person's yes (`OfflineUploader`): masters in the order projects, BOQ, workers, wallets, each created with its stored `client_uuid`, then every local id moved in one transaction across tables and queued payloads. Queued entries then go through the normal sync. Interrupted uploads resume; rows that fail go to the Outbox as usual.

### Where to free a phone

Both `409 device_limit` and `410 licence_revoked` carry `manage_url` at the top level of the reply, next to `code` and `message` (`DeviceLicenceService`). The phone keeps the server's value in `OfflinePlanState.manageUrl` and shows it with a copy button. The address it builds itself from the API address (`<server>/offline/devices`) is used only when the reply has no `manage_url`, or has one that is not an http or https address. The phone giving its own slot back (`POST offline/devices/release`) has no refusal to read, so it uses the built address. Checked against the local server: a freed phone's refresh answers 410 with `manage_url: http://localhost:8361/offline/devices`.

### When the licence ends

The owner's rule: the app must not keep working offline forever. The token's signed `exp` is the end. Claims also carry `iat`, the moment the server signed it; the phone keeps both from the verified token (`OfflinePlanState.expiresAt`, `issuedAt`), re-reading them at every start.

- **Last 7 days (`kLicenceWarning`).** A calm card, `LicenceNotice`, on Home and on every screen that adds or changes something, says the date and to connect to the internet once. Nothing is blocked. Seven, because a site may be without a network for days. A company on any other plan sees no card: it is an empty box.
- **After `exp`, new entries and edits are refused.** `OfflinePlan.assertCanWrite()` throws `LicenceEnded` (an `ApiFailure`, so every form that says "could not save" already stops cleanly). Every repository method that adds or changes a record calls it first, through `DataContext.assertCanWrite`, before it touches the database: daily report (draft, edit, photo, send), measurement, issue (raise, status, photos), attendance (mark, mark all, scan, selfie, workers), petty cash (expense, top-up, wallet), material, progress, machine log, inspections, contractor payments, approvals, and setting up projects and BOQ lines. `createLocal` and `createOnline` guard as a backstop. A refusal stores nothing and deletes nothing. `test/core/offline/licence_expiry_test.dart` calls every one of them after the end and compares every table and kept value before and after, and reads the repository source to fail on a new write path that has no guard or no line in that test.
- **What keeps working.** Reading everything on the phone, signing out, and the export. Nothing is deleted. The only kept values the app writes after the end are caches and preferences (`refresh*`, the language, the open project).
- **Where a refusal leads.** Every entry form route (`Routes.entryForms`) redirects to `Routes.licenceEnded` (`LicenceEndedScreen`) while ended, and a write that is refused from a button in place (a mark on the muster, a status on an issue) opens the same page through `OfflinePlan.refusals`. The page says it ended, offers the export, and has "Try renewing now" (`OfflinePlan.renewNow`, which answers `LicenceRenewal`). A freed phone, a full plan and a lapsed sign-in show the same card as the plan page.
- **Renewing.** The moment the phone is online, and at every beat of the scheduler, the plan asks for a new token. A phone in its last week, past the end, or with no token asks at every chance (`nearEndInterval`, 30 seconds), not once an hour. A fresh verified token lifts the block as it arrives: `licenceChanges` reaches the gate and the screens, with no restart.
- **Whatever the server now says about the plan.** Any phone that keeps its data local (any `OfflinePlanState`, in any `mode`) is blocked once its token has expired. A lapsed trial that falls back to a free plan, with the owner declining the upload, does not mean unlimited offline use. The phone can still be read, exported and uploaded. Only a fresh token (the plan going back to offline, then `renewNow`) or a finished upload lifts the block. `LicenceStatus.planSyncs` marks the case: the notice says to upload, and the renewal page puts "Upload to the server" first, with "Try renewing now" below it, which answers `LicenceRenewal.planChanged` while the plan syncs. The upload itself (`mode: uploading`) is not held back: every screen leads to the upload page while it runs, and it has to queue the saved reports (`DprRepository.send`), so `statusAt` leaves `uploading` unblocked. A phone that leaves `uploading` by saying no is blocked again.
- **No valid token at all.** A local-only phone with no token (never activated, activation refused for a full plan or not answered, a first reply that failed its signature check, or a stored state with its token or key taken away) is blocked the same way. `LicenceStanding.none` counts as blocked, and `LicenceStatus.hasNoLicence` is true. The renewal page says "This phone has no licence yet", has the same "Try renewing now" (which activates), and shows the card for a full plan (with the server's `manage_url`), a freed phone or a lapsed sign-in. Companies on a normal plan have no `OfflinePlanState`, so none of this applies to them.
- **A clock set back.** The licence is judged by `OfflinePlan.now()`, the latest of the phone's clock, the latest moment the app has ever seen (`MetaKey.offlineSeen`, saved at most once a minute by the one-minute watch started in `AppServices.start`, and on every start and renewal), and the `iat` of the current token. A token that fails its signature check, or an end date with no token, is `LicenceIssue.invalid` and counts as ended. The one way the remembered time can go down is a verified fresh token: its `iat` is the server's own time, so a phone whose clock once ran far ahead recovers when it next renews, and no clock can use that to gain days.
- **Normal plans.** `assertCanWrite` returns at once when there is no offline state, and nothing about the licence is stored (`offline.seen` is written only on the plan). The existing suites for every other plan are unchanged.

