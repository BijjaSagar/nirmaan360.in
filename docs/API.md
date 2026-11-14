# Mobile API

The field app's API lives under `/api/v1` and takes a Sanctum bearer token. The rules every endpoint follows (money and quantity as strings, dates, `client_uuid`, pagination) are in `mobile/docs/CONTRACT.md`. This file adds the endpoints built since then.

## Sign-up

### `POST /auth/register`

Starts a new company with the person as its owner, and signs them in on this device. It takes no token.

```json
{"company": "Deccan Builders", "name": "Priya Rane", "email": "priya@example.com", "phone": "9876543210",
 "password": "a-long-password", "password_confirmation": "a-long-password", "device_name": "Pixel 8", "locale": "mr"}
```

| Field | Rules |
| --- | --- |
| `company` | required, up to 120 characters |
| `name` | required, up to 120 characters |
| `email` | required, a valid email, up to 190 characters. Stored trimmed and in lower case. Refused if any account has it in any letter case, or if it is a platform admin's email. |
| `phone` | optional, up to 20 characters, compared exactly as typed. Refused if an account has it. |
| `password` | required, at least 8 characters, with a matching `password_confirmation` |
| `device_name` | required, up to 120 characters. Names the token, as on `/auth/login`. |
| `locale` | optional: `en`, `mr` or `hi`. Defaults to `en`. |

**201** answers `{"token": "...", "user": {...}}`, the same keys and the same `user` as `/auth/login` gives for this person.

- The company starts on the `free` plan, active, with no trial, so nothing started in the app needs paying for outside it (App Store guideline 3.1.1). The website's sign-up still starts a trial of the default plan.
- The app does not choose a plan: a `plan` in the body is ignored.
- If the free plan has been renamed, taken off sale or given a price, the sign-up answers 422 on `errors.signup` and no company is made. It never falls back to a paid plan or a trial. `signup` is not a form field: treat it as a problem on the server to try again later, not as a mistake in what the person typed.
- The reply carries no plan, trial or price.

Errors:
- **422** in Laravel's shape. `errors.email` covers an email that is taken or cannot be used. `errors.phone` covers a mobile number that is taken. Any other field can also appear. The texts are for people, so read the field, not the wording.
- **429** comes after 5 attempts a minute from one IP address. This counter is separate from the one `/auth/login` uses.

The same email is never accepted twice. If a reply is lost and the app sends the same sign-up again, it gets 422 on `email`. Signing in with the same email and password then completes it. Sign-ups do not use `client_uuid`.

`/auth/login` finds the email in any letter case. If two old accounts differ only by case, only the exact spelling signs in.

## Plan refusals

A reply under `/api/v1` that a plan stops never tells the person to upgrade, buy, renew or go to billing or pricing, because the app shows its `message` as it is. It says what the plan stops and that the company's owner can check the plan, for example a module outside the plan (403), the project limit (422 on `errors.plan`) and no AI credits left (402, `reason` `no_credits`). The website keeps its own wording.

## `GET /offline/status`

There is no offline plan: every company syncs. Field app builds made while it existed (build 18 among them) still ask this after every sign-in and every few hours, for every company, and read only `offline`. The answer is always the one an online company had:

```json
{"plan": "contractor", "offline": false, "syncs": true}
```

A phone that still holds a company from the old offline plan reads `false` as "the company syncs now" and asks the owner before it uploads what it holds, so keep this endpoint until no phone runs one of those builds. The old licence endpoints (`/offline/public-key`, `/offline/devices/activate`, `/offline/devices/refresh`, `/offline/devices/release`) are gone and answer 404, and no reply carries the `offline_plan` code any more.

## Deleting an account

Anyone signed in can delete their own account: staff of every role and contractor logins. There is no permission to check. The website has the same flow at `/account/delete`.

The server decides what happens, under a lock on the company, and the app never chooses:
- `account`: a member, or an owner while another **active** owner exists. The account is anonymised at once. Their name, email, mobile number, sign-ins, phones, notification settings and assistant chats are removed. Everything they recorded stays with the company and shows as "Deleted user".
- `company`: the company's only active owner. The whole company is erased after the 30-day grace period, by the nightly job at 02:30, so up to a day later than the date given. Their account is not anonymised before that, because the company still needs an owner who can sign in and cancel. All of their sign-ins are ended now.

### `GET /auth/account/deletion`

What deleting would do, for the person to read first.

```json
{"data": {"mode": "company", "company_name": "Deccan Builders", "other_people": 3, "requires_confirm_name": true,
  "grace_days": 30, "erase_after": "2026-12-12T15:04:05+05:30",
  "pending": {"erase_after": "2026-12-12T15:04:05+05:30", "can_cancel": true}}}
```

- `mode`: `account` or `company`.
- `other_people`: the company's other active users, contractor logins included.
- `requires_confirm_name`: true only in `company` mode.
- `erase_after`: only in `company` mode, otherwise `null`. If a deletion is already pending, it is that request's date. Otherwise it is now plus `grace_days`. Times are ISO 8601 in Asia/Kolkata.
- `pending`: `null`, or the company's pending deletion, which anyone in the company sees. `can_cancel` is true only for owners.

### `POST /auth/account/deletion`

```json
{"mode": "account", "password": "..."}
{"mode": "company", "password": "...", "confirm_name": "Deccan Builders"}
```

| Field | Rules |
| --- | --- |
| `mode` | required, `account` or `company`: the mode the preview showed |
| `password` | required. The account's password, always, even for people who signed in another way. |
| `confirm_name` | required when `mode` is `company`, up to 255 characters, the company name exactly as shown |

Answers:
- **200** `{"data": {"result": "deleted"}}`. The token this call used is already revoked, so do not call `/auth/logout`.
- **200** `{"data": {"result": "scheduled", "erase_after": "..."}}`. Every token this person holds is revoked. Asking again after signing back in returns the same `erase_after`.
- **409** `{"message": "...", "code": "deletion_mode_changed"}`. The mode sent is no longer the one that applies, for example because the other owner left. Load the preview again.
- **422** in Laravel's shape, on `password` ("That password is not right."), `confirm_name` or `mode`. Nothing changes.
- **429** after 5 attempts a minute by one person, with `Retry-After`. This counter is used only by deletion.
- **401** for a token that is not valid.

### `DELETE /auth/account/deletion`

Cancels the company's pending deletion. Owners only.

- **200** `{"data": {"result": "cancelled"}}`, or `{"data": {"result": "none"}}` when nothing was pending.
- **403** `{"message": "Only a company owner can do this."}`.
- **429** after 10 a minute, on its own counter.

If the only owner deleted their account and another owner then cancels, the company stays and the requester's account is anonymised as in `account` mode. If the requester cancels it themselves, nothing else happens.

Support can do the same for someone who cannot sign in, after checking the request by email: `php artisan account:delete {email}`. It asks before it acts unless `--force` is given.

## Editing and deleting a BOQ line

Both go through `BoqService`, as the web BOQ page does, and each change is kept in the BOQ history with its reason (source `app_edit` or `app_remove`) and in `audit_logs`. `GET /me` carries `is_owner`: true only for a built-in owner, never for an admin or a custom role.

### `PATCH /boq-items/{item}`

`boq.manage`. Body: `code`, `description`, `unit`, `qty`, `reason` (all required), `rate` (required with `money.view`; without it the rate is ignored and kept), `category`, `details`, `updated_at` (optional). The section is not changed here.

- **200** `{"data": {...}}`, the line in the `boq_items` pull shape. The same values again change nothing and write no history.
- **409** `{"message", "code": "changed", "data": {...}}` when `updated_at` is given and the line changed since, to the second. `data` is the line as it is now.
- **422** `errors.qty` when the quantity is cut below what is measured or billed to the client (bills not cancelled). Measured is the approved total plus pending entries that add work; a pending deduction or reversal does not lower it, and rejected entries do not count. Keeping or raising the quantity is never refused, so a rate or wording fix goes through on a line measured past its quantity. `errors.unit` once anything below is recorded against the line.
- **404** another company's line, a project the person is not given, or a removed line.

### `DELETE /boq-items/{item}`

`boq.manage`, and the company owner only. Body: `reason` (required).

- **200** `{"data": {"id": 12, "deleted": true}}`, also for a line already removed, so a retry is safe.
- **403** `{"message": "Only the company owner can delete a BOQ item."}` for anyone else, an admin included.
- **422** `errors.boq`, one message per kind of record that holds the line: measurements, work in a daily report that is not deleted, a contractor tender, a work order that is not deleted, an RA bill, site progress entries, labour. The website's remove refuses the same records.

## A project's advance in lines, and its recovery percentage

The mobilisation advance can be several lines, each with an amount, the date it came in and a note, and the advance recovery percentage can be worked out from the advance and the contract value. These are additions to `GET` and `PATCH /projects/{project}`: no key is renamed or changes type, and a request that sends neither new field behaves as before. `advance_amount` and `advance_recovery_pct` stay the figures RA bills use, so a bill already made keeps the terms saved on it and new bills use the new ones.

### `GET /projects/{project}` and the `PATCH` reply

`project.view`. Two keys are added after `budget_margin_pct`:

- `advances`: the lines in their order, `[{"id": 7, "amount": "60000.00", "received_on": "2026-11-02", "note": "NEFT 30218"}]`. `received_on` and `note` may be null. The lines add up to `advance_amount`. An empty list means the advance is one figure, as on every project saved before this. `null` without `money.view`, like `advance_amount`.
- `advance_recovery_auto` (bool): true while `advance_recovery_pct` is worked out. Sent to everyone, as `advance_recovery_pct` is. False on every project that existed before, so no saved percentage changed by itself.

### `PATCH /projects/{project}`

`project.manage`. Two optional fields are added, both taken only from someone with `money.view`, as `contract_value` and `advance_amount` are; from anyone else they are ignored and the saved values stay.

- `advances`: the whole list, replacing the saved lines. Each line is `{amount, received_on, note}`: `amount` above zero, up to 9999999999999; `received_on` a date or null; `note` up to 255 characters or null. A line whose amount is null or empty is left out, with whatever else it holds. At most 20 lines. `advance_amount` becomes the sum of the lines, and an `advance_amount` sent beside them is ignored. An empty list takes the lines away and sets the advance to 0. While a project has lines, an `advance_amount` sent on its own must equal their sum.
- `advance_recovery_auto` (bool). While it is true the server sets `advance_recovery_pct` to `advance_amount` ÷ `contract_value` × 100, rounded half up to two places, at most 100, and 0 when either is 0, and works it out again on every change, including a change of `phases` or `contract_value`. A sent `advance_recovery_pct` is then ignored. False keeps the saved percentage until one is sent.

Errors are 422 in the usual shape: `errors["advances.0.amount"]`, `errors["advances.0.received_on"]` or `errors["advances.0.note"]` on the line, `errors.advances` for more than 20 lines or lines that add up to more than a project holds, `errors.advance_amount` for a figure that differs from the lines. Another company's project, or one the person is not given, is 404.

Each change to the lines is in `audit_logs` as `project.advances_changed`, with each line's amount, date and whether it has a note. The note itself is not copied there.

## Tender API

The app's side of the web page "Tenders for this BOQ line": see which tenders a BOQ line is in, start a tender from the line, add contractors and their quotes, compare the quotes and choose one. Choosing a quote makes the work order at its rates.

Every endpoint takes the same permissions as its web page. All but the BOQ list also need the `site_control` plan feature, and answer 403 without it.

| Method and path | Permission | Answers |
| --- | --- | --- |
| `GET /projects/{project}/boq` | `boq.view` | 200, each line with a `tenders` marker |
| `GET /boq-items/{item}/tenders` | `boq.view` + `contractor.view` | 200 |
| `POST /boq-items/{item}/tenders` | `money.view` + `contractor.tender` | 201, or 200 for a repeated `client_uuid` |
| `GET /contractors?q=` | `money.view` + `contractor.tender` | 200, paginated |
| `POST /contractors` | `money.view` + `contractor.tender` | 201 for a new contractor, 200 for a name already there |
| `GET /tenders/{tender}` | `boq.view` + `contractor.view` | 200 |
| `POST /tenders/{tender}/bids` | `money.view` + `contractor.tender` | 201, or 200 for a repeated `client_uuid` |
| `GET /bids/{bid}/quotation` | `money.view` + `contractor.view` | the file's bytes |
| `POST /tenders/{tender}/award` | `money.view` + `contractor.award` | 201, or 200 when that quote was already chosen |

With the default roles: owner and project manager may do everything. The estimator (QS) may start tenders and add contractors and quotes, but not choose. Accounts sees every figure but may not change a tender. Site engineer and viewer see the tenders and who quoted, without a price. The store keeper does not see tenders at all.

**Money.** Without `money.view` the tender endpoints leave the keys for prices out, rather than sending null. (The BOQ list keeps its `rate` key as null, as before.) That covers rates, amounts, differences, the lowest flags, payment terms, remarks, the quotation file, the tender's scope and the award note, because free text may quote a price. Quotes then come current first in the order received, since cheapest first would itself say who quoted least.

**Write replies.** `POST /boq-items/{item}/tenders`, `POST /tenders/{tender}/bids` and `POST /tenders/{tender}/award` answer with the whole tender, as `GET /tenders/{tender}` shows it, only to someone who may read that (`boq.view` and `contractor.view`). A role that may enter or choose quotes without that read gets only `{id, number, title, status, work_order_id, work_order_number}` in `data`, with `bid_id` or `work_order` beside it as usual.

**Errors.** A record of another company, or of a project the person is not given, answers 404, whatever its id. A missing permission answers 403. Validation answers 422 in Laravel's shape, `{"message": "...", "errors": {"field": ["..."]}}`.

**`can`.** The line and the tender say what this person may do. `can.quote` means they may start a tender from the line and add quotes. `can.award` means they may choose a winner. On a tender both are false once it is no longer `OPEN`.

### `GET /projects/{project}/boq`

Each line carries `amount` (quantity × rate) and `balance_amount` (balance quantity × rate), and the reply carries `totals` beside `data`: the whole BOQ and each section in BOQ order, the same figures as the web BOQ page. They are money, so without `money.view` the line fields are null and `totals` is null. `amount` and `balance_amount` also come in `/sync/pull` `boq_items`, which share the line's shape.

Each line also gains `tenders`: the marker the web BOQ table shows. It counts tenders that are not cancelled, and only each contractor's latest quote. `lowest_rate` is only there for `money.view`. `tenders` is null for a line with no tender, and on every line for someone who may not see tenders (no `contractor.view`, or a plan without the module).

`tenders` is not in `/sync/pull`: a new quote changes the marker without changing the line, so a delta pull would not carry it. Read it from this endpoint while online.

```json
{"data": [{
  "id": 1, "project_id": 1, "section": "Superstructure", "code": "2.1", "description": "RCC M25 in slab", "unit": "cum",
  "qty": "100.000", "rate": "1000.00", "amount": "100000.00", "balance_amount": "100000.00", "sort": 0,
  "measured_qty": "0.000", "approved_qty": "0.000", "balance_qty": "100.000",
  "updated_at": "2026-11-10T18:50:19+05:30",
  "tenders": {"tenders": 1, "quotes": 2, "lowest_rate": "900.00"}
}],
 "totals": {"amount": "100000.00", "balance_amount": "100000.00",
            "sections": [{"section": "Superstructure", "amount": "100000.00", "balance_amount": "100000.00"}]}}
```

### `GET /boq-items/{item}/tenders`

Every tender that holds this line, newest first, with each contractor's rate for this line against the BOQ rate the tender was made at. `no_quantity` is true when the line's quantity is zero: such a line cannot start a tender. A cancelled tender is still listed but is not counted.

```json
{"data": {
  "boq_item_id": 1, "code": "2.1", "description": "RCC M25 in slab", "unit": "cum", "qty": "100.000",
  "no_quantity": false, "tender_count": 1, "open_tender_count": 1, "quote_count": 2,
  "boq_rate": "1000.00", "boq_amount": "100000.00", "lowest_rate": "900.00", "lowest_contractor": "Shree Tiles",
  "can": {"quote": true, "award": true},
  "tenders": [{
    "id": 1, "number": "CT-2026-27-0001", "title": "Flooring", "status": "OPEN", "due_on": "2026-11-25",
    "work_order_id": null, "work_order_number": null, "qty": "100.000",
    "boq_rate": "1000.00", "boq_amount": "100000.00", "lowest_rate": "900.00",
    "lines": [{"id": 1, "boq_item_id": 1, "code": "2.1", "description": "RCC M25 in slab", "unit": "cum", "qty": "100.000"}],
    "quote_count": 2,
    "quotes": [{
      "bid_id": 2, "contractor": "Shree Tiles", "received_on": "2026-11-09", "current": true, "selected": false,
      "valid_until": "2026-12-31", "includes_material": true, "completion_days": 45, "entered_by": "Anil Patil",
      "rate": "900.00", "amount": "90000.00", "diff": "-100.00", "diff_amount": "-10000.00", "diff_pct": "-10.00", "lowest": true,
      "payment_terms": "30 days after the bill", "remarks": "Tiles from Morbi", "has_quotation": true, "quotation_name": "quote.pdf"
    }]
  }]
}}
```

Without `money.view`, the same with `boq_rate`, `boq_amount`, `lowest_rate`, `lowest_contractor`, and in each quote everything from `rate` on, left out.

### `POST /boq-items/{item}/tenders`

Starts a tender holding just this line. A `boq_item_ids` sent along is ignored. To put several lines in one tender, use the web.

```json
{"title": "Flooring", "scope": "Vitrified tiles, with skirting", "due_on": "2026-11-25", "client_uuid": "6f1c..."}
```

`title` is required (up to 160 characters). `scope` (up to 5000) and `due_on` (`YYYY-MM-DD`) are optional. It answers with the tender, in the shape of `GET /tenders/{tender}`. A line with no quantity answers 422:

```json
{"message": "These BOQ lines have no quantity yet. Enter the quantity in the BOQ first, so the quotes can be compared.",
 "errors": {"boq_item_ids": ["These BOQ lines have no quantity yet. Enter the quantity in the BOQ first, so the quotes can be compared."]}}
```

### `GET /contractors?q=`

The company's subcontractors for the picker on the quote form, by name, 100 a page (`?page=`). `q` matches part of the name in any case, or part of the phone number.

```json
{"current_page": 1, "last_page": 1, "per_page": 100, "total": 1, "next_page_url": null,
 "data": [{"id": 7, "name": "Shree Tiles", "phone": "9800000001"}]}
```

### `POST /contractors`

Adds a subcontractor. Only `name` is required.

```json
{"name": "Shree Tiles", "contact_person": "Site office", "phone": "9800000001", "email": null, "gstin": "27AAAAA0000A1Z5", "address": "Pune", "client_uuid": "1b2e..."}
```

A name the company already has, in any case, returns that contractor unchanged with 200 and a message to show. That is also how a repeat is recognised.

```json
{"data": {"id": 7, "name": "Shree Tiles", "phone": "9800000001"}, "message": "Shree Tiles is already on your list."}
```

For a new contractor `message` is null and the status is 201.

### `GET /tenders/{tender}`

One tender with every quote set against the BOQ. For `money.view`, the current quotes come cheapest first, then the replaced ones newest first. `lines[].id` is the tender line id that a quote's `rates` are keyed by. A quote's `amount`, `diff` and `diff_pct` are its total against the tender's `boq_amount`. Each entry in `rates` is one line's rate against that line's BOQ rate. `replaced_by` is the newer quote from the same contractor that replaced this one, and only quotes with `current: true` can be chosen.

```json
{"data": {
  "id": 1, "number": "CT-2026-27-0001", "title": "Flooring", "status": "OPEN", "due_on": "2026-11-25", "project_id": 1,
  "work_order_id": null, "work_order_number": null, "awarded_at": null,
  "scope": "Vitrified tiles, with skirting", "award_note": null, "boq_amount": "100000.00", "lowest_amount": "90000.00",
  "lines": [{"id": 1, "boq_item_id": 1, "code": "2.1", "description": "RCC M25 in slab", "unit": "cum", "qty": "100.000", "boq_rate": "1000.00", "boq_amount": "100000.00"}],
  "quote_count": 2,
  "can": {"quote": true, "award": true},
  "quotes": [
    {"bid_id": 2, "party_id": 7, "contractor": "Shree Tiles", "received_on": "2026-11-09", "current": true, "replaced_by": null, "selected": false,
     "valid_until": "2026-12-31", "includes_material": true, "completion_days": 45, "entered_by": "Anil Patil",
     "amount": "90000.00", "diff": "-10000.00", "diff_pct": "-10.00", "lowest": true,
     "rates": [{"tender_item_id": 1, "boq_item_id": 1, "rate": "900.00", "amount": "90000.00", "diff": "-100.00"}],
     "payment_terms": "30 days after the bill", "remarks": "Tiles from Morbi", "has_quotation": true, "quotation_name": "quote.pdf"},
    {"bid_id": 3, "party_id": 8, "contractor": "Om Builders", "received_on": "2026-11-10", "current": true, "replaced_by": null, "selected": false,
     "valid_until": null, "includes_material": null, "completion_days": null, "entered_by": "Anil Patil",
     "amount": "105000.00", "diff": "5000.00", "diff_pct": "5.00", "lowest": false,
     "rates": [{"tender_item_id": 1, "boq_item_id": 1, "rate": "1050.00", "amount": "105000.00", "diff": "50.00"}],
     "payment_terms": null, "remarks": null, "has_quotation": false, "quotation_name": null},
    {"bid_id": 1, "party_id": 8, "contractor": "Om Builders", "received_on": "2026-11-10", "current": false, "replaced_by": 3, "selected": false,
     "...": "the same keys"}
  ]
}}
```

`status` is `OPEN`, `AWARDED` or `CANCELLED`. `diff_pct` is null when the BOQ amount is zero. Without `money.view` a quote carries only `bid_id`, `party_id`, `contractor`, `received_on`, `current`, `replaced_by`, `selected`, `valid_until`, `includes_material`, `completion_days` and `entered_by`. Each line then carries no `boq_rate` or `boq_amount`, and `scope`, `award_note`, `boq_amount` and `lowest_amount` are left out.

### `POST /tenders/{tender}/bids`

Adds a quote. Send it as `multipart/form-data` when a quotation file comes with it, otherwise JSON works too.

| Field | Notes |
| --- | --- |
| `party_id` | a subcontractor from `GET /contractors`. Or leave it out and send `new_contractor` |
| `new_contractor`, `new_contractor_phone` | adds the contractor as `POST /contractors` does, or uses the one with that name |
| `rates[<tender line id>]` | a rate for every line of the tender, keyed by `lines[].id`: `rates[1]=900` in a form, `{"rates": {"1": "900"}}` in JSON |
| `quoted_amount` | or one figure for the whole tender, spread across the lines in proportion to their BOQ amounts. Sent with `rates`, it must equal their total |
| `received_on` | `YYYY-MM-DD`, not after today. Defaults to today |
| `valid_until` | not before `received_on` (or today) |
| `includes_material` | `true`/`false`, or `1`/`0` in a form |
| `completion_days` | 1 to 3650 |
| `payment_terms`, `remarks` | up to 500 characters each |
| `quotation` | optional file: pdf, jpg, jpeg, png or webp, up to 8 MB |
| `client_uuid` | a repeat returns the quote first saved, and keeps only the first file |

Money is sent as a string or number with at most two decimals. A later quote from the same contractor replaces their earlier one, which stays on record with `current: false`. It answers with the whole tender, as `GET /tenders/{tender}` gives it, and the new quote's id:

```json
{"data": {"id": 1, "status": "OPEN", "quote_count": 2, "quotes": ["..."], "...": "as GET /tenders/{tender}"}, "bid_id": 2}
```

Errors come back against the field: `party_id` (no contractor, or one of another company), `rates` (a line without a rate, or a total of zero), `quoted_amount` (no price at all, or not the rates' total), `quotation` (wrong type or too large), `tender` (the tender is no longer open).

### `GET /bids/{bid}/quotation`

Streams the quotation file the contractor sent, with its own content type and `X-Content-Type-Options: nosniff`. Use it when `has_quotation` is true. It answers 404 when the quote has no file.

### `POST /tenders/{tender}/award`

Chooses a current quote. The work order is made at that quote's rates, so measurements, RA bills and payments run on it as on any other contract.

```json
{"bid_id": 2, "start_date": "2026-11-15", "end_date": "2027-01-31",
 "retention_pct": "5", "tds_pct": "1", "gst_pct": "18", "advance_amount": "0", "advance_recovery_pct": "0",
 "deduction_basis": "gross", "deduction_order": "retention,advance,material,tds", "award_note": "Best terms", "client_uuid": "9a0d..."}
```

The percentages, `advance_amount`, `deduction_basis` (`gross` or `running`) and `deduction_order` are required. The web form starts them at 5, 1, 18, 0, 0, `gross`. `deduction_order` is one of `retention,advance,material,tds`, `advance,retention,material,tds` or `tds,retention,advance,material`. It answers with the tender, now `AWARDED`, and the work order:

```json
{"data": {"id": 1, "status": "AWARDED", "work_order_id": 12, "work_order_number": "WO-RT01-003", "...": "as GET /tenders/{tender}"},
 "work_order": {"id": 12, "number": "WO-RT01-003"}}
```

Sending the same choice again returns the same work order with 200. A replaced quote, or one from another tender, answers 422 on `bid_id`. A tender that is no longer open answers 422 on `tender`.

## Material loop from site

Seven routes that let the field app answer a material flag, attach a bill, report a short delivery and read wastage against the BOQ norm. They reuse the web's services and permissions. Nothing here is kept on the phone or waits in the outbox.

| Route | Permission | Service |
| --- | --- | --- |
| `POST /projects/{project}/material-control/reasons` | `material_control.reason` | `MaterialCheckService::addReason` |
| `GET /delivery-challans/{deliveryChallan}` | `material_control.view` | `MaterialControlPresenter::challanDetail` |
| `POST /delivery-challans/{deliveryChallan}/bill` | `material_control.bills` | `DeliveryChallanService::attachBill` |
| `GET /delivery-challans/{deliveryChallan}/bill` | `material_control.view` and `money.view` | the stored file |
| `POST /delivery-challans/{deliveryChallan}/short` | `material_control.challan` | `ShortDeliveryService::report` |
| `GET /projects/{project}/material-control/bills` | `material_control.bills` | bills pending, or `?show=attached` |
| `GET /projects/{project}/material-reconciliation` | `procurement.view` | `ReconciliationService`, read only |

The first six sit behind `feature:site_control`, the last behind `feature:procurement`. `procurement.view` is now one of the `can` keys in `GET /me`. A challan or project of another company, or outside the user's project limit, answers 404.

A reason and a short need a `client_uuid` and answer a repeat with the first row: 201 the first time, 200 after. The lookup runs before the field rules, so a retry sent the next day is not refused for its date, and a `client_uuid` already used on another project or challan is a 422 on `client_uuid`. Attaching a bill creates no record: it replaces the file and takes no `client_uuid`.

Money: `bill_no`, `bill_date`, `bill_amount`, `vendor_bill_id` and `has_bill_file` leave the server only with `money.view`. Without it the keys are absent, not null, and the same fields are ignored on a request. `remarks`, `reversal_reason` and a short's `note` are words a person typed: one that names a rupee figure (`MoneyMention`) is sent as `null` without `money.view`, and the key stays. The reconciliation carries quantities only.

### `POST /projects/{project}/material-control/reasons`

```json
{"material_id": 5, "flag_type": "over_used", "reason": "Slab 2 had an extra drop beam.", "client_uuid": "9a0d..."}
```

`flag_type` is one of `over_used`, `under_used`, `unaccounted`, `over_received` or `short_delivery`, and `reason` is at most 2000 characters. As on the web form, the gap is stored as it stood (`gap_qty`), so the flag opens again, with no reason, once the gap grows past it. A flag that is no longer on the check answers 422 on `flag_type`.

```json
{"data": {"id": 3, "client_uuid": "9a0d...", "project_id": 1, "material_id": 5, "flag_type": "over_used", "gap_qty": "5.000",
          "reason": "Slab 2 had an extra drop beam.", "created_by": "Sachin More", "created_at": "2026-11-10T10:15:00+05:30"}}
```

### `GET /delivery-challans/{deliveryChallan}`

One challan in full: the challan list row, then names, remarks, who did what and the shortages found on it. The five bill keys marked above come only with `money.view`.

```json
{"data": {
  "id": 12, "number": "DC-2026-27-0012", "client_uuid": "9a0d...", "project_id": 1, "party_id": 7, "supplier": "Gupta Traders",
  "store_id": 1, "material_id": 5, "unit_id": 10, "qty": "38.000", "base_qty": "38.000", "challan_qty": "40.000",
  "challan_no": "DC-881", "challan_date": "2026-11-01", "vehicle_no": "MH12AB1234", "has_photo": true,
  "bill_status": "attached", "status": "POSTED",
  "bill_no": "INV-77", "bill_date": "2026-11-04", "bill_amount": "98765.43", "vendor_bill_id": null, "has_bill_file": true,
  "material": "Cement", "unit": "bag", "store": "Site store", "remarks": "Two bags torn, left at gate.",
  "created_by": "Sachin More", "created_at": "2026-11-01T09:30:00+05:30",
  "bill_attached_by": "Priya Shah", "bill_attached_at": "2026-11-04T16:00:00+05:30",
  "reversed_by": null, "reversed_at": null, "reversal_reason": null, "supplier_warning": null,
  "shorts": [{"id": 4, "client_uuid": null, "project_id": 1, "delivery_challan_id": 12, "material_id": 5, "unit_id": 10,
              "short_qty": "2.000", "short_base_qty": "2.000", "found_on": "2026-11-01", "note": null,
              "at_entry": true, "status": "POSTED", "created_by": "Sachin More"}]
}}
```

`status` is `POSTED` or `REVERSED`, and `bill_status` is `pending` or `attached`. `reversed_at` is a date, and `created_at` and `bill_attached_at` are timestamps. `supplier_warning` is the supplier's repeated-shortage warning, or null. A short's `at_entry` is true for a shortage counted at the gate (only the counted quantity entered stock) and false for one reported later (stock was corrected). The photo is `GET /delivery-challans/{deliveryChallan}/photo` as before.

### `POST /delivery-challans/{deliveryChallan}/bill`

`multipart/form-data` with the file as `bill` (jpg, jpeg, png, webp or pdf, up to 10 MB). From `money.view` it also reads `bill_no` (at most 60), `bill_date` (not after today), `bill_amount` (above 0, two decimals) and `vendor_bill_id` (a vendor bill of this project). From anyone else those fields are ignored, not refused. A file, or from `money.view` a `bill_no` or `vendor_bill_id`, must be sent, else 422 on `bill`. A reversed challan answers 422 on `status`. The reply is `{"data": ...}` with the challan in full, as `GET /delivery-challans/{deliveryChallan}`.

### `GET /delivery-challans/{deliveryChallan}/bill`

Streams the bill file with its own content type. It answers 403 without `money.view` and 404 when the challan has no file. Offer it only when `has_bill_file` is true.

### `POST /delivery-challans/{deliveryChallan}/short`

```json
{"short_qty": "3", "found_on": "2026-11-09", "note": "Counted at unloading.", "client_uuid": "9a0d..."}
```

`short_qty` is in the challan's unit, above 0 with up to three decimals; `found_on` is not after today; `note` is at most 500 characters. The quantity leaves stock as a negative receipt, the supplier's record counts it, and the people who can write the reason are notified. Shortages reported later cannot add up to more than the challan (422 on `short_qty`), and a reversed challan answers 422 on `status`. Reversing a short stays on the website.

```json
{"data": {"id": 5, "client_uuid": "9a0d...", "project_id": 1, "delivery_challan_id": 12, "material_id": 5, "unit_id": 10,
          "short_qty": "3.000", "short_base_qty": "3.000", "found_on": "2026-11-09", "note": "Counted at unloading.",
          "at_entry": false, "status": "POSTED", "created_by": "Sachin More"},
 "challan": {"id": 12, "...": "the challan in full, as GET /delivery-challans/{deliveryChallan}"}}
```

### `GET /projects/{project}/material-control/bills`

Posted challans still waiting for a bill, or with `?show=attached` those that have one, newest first, 25 a page (`?page=`). Each row is the challan list row plus `material`, `unit`, `days_waiting` and `overdue`:

```json
{"data": [{"id": 12, "number": "DC-2026-27-0012", "supplier": "Gupta Traders", "material": "Cement", "unit": "bag",
           "qty": "200.000", "challan_no": "C-1", "challan_date": "2026-11-01", "bill_status": "pending",
           "days_waiting": 9, "overdue": true, "...": "the other list row keys"}],
 "meta": {"show": "pending", "pending_count": 2, "alert_after_days": 7, "current_page": 1, "last_page": 1, "total": 2}}
```

`days_waiting` counts whole days from the challan date and is null once a bill is attached. `overdue` is true from `alert_after_days` on, the same line the site alert for challans without bills uses (`modules.site_alerts.challan_bill_days`). `pending_count` counts pending bills whichever list is shown.

### `GET /projects/{project}/material-reconciliation`

`?basis=measured|approved|dpr`, default `measured`; any other value is read as `measured`. It is the web's reconciliation page, read from `ReconciliationService`, whose figures come from `Procurement\WastageAllowance`. Every figure is a quantity in the material's base unit, so nothing here depends on `money.view`.

```json
{"data": {
  "basis": "measured", "bases": ["measured", "approved", "dpr"],
  "counts": {"wastage": 1, "under": 0, "no_work": 0, "no_norm": 1, "ok": 3},
  "rows": [{"boq_item_id": 11, "boq_code": "2.1", "boq_description": "RCC M25 in slab", "boq_unit": "cum", "executed": "50.000",
            "material_id": 5, "material_name": "Cement", "unit": "bag", "actual": "450.000", "norm": "8.400000", "wastage_pct": "2.00",
            "theoretical": "420.000", "allowed": "428.400", "variance": "30.000", "variance_pct": "7.14", "status": "wastage"}],
  "unallocated": [{"material_id": 5, "material_name": "Cement", "unit": "bag", "activity": "Site office plinth", "actual": "40.000"}],
  "below_reorder": [{"store_id": 1, "store": "Site store", "material_id": 5, "material_name": "Cement", "unit": "bag",
                     "balance": "35.000", "reorder_level": "100.000", "shortfall": "65.000"}]
}}
```

`executed` is the work done on the chosen basis. `theoretical` is `executed` times `norm`, `allowed` is `theoretical` times (1 + `wastage_pct` / 100), and `variance` is `actual` minus `theoretical`. A row's `status` is `wastage` (used beyond what is allowed), `under` (less than theoretical), `ok`, `no_work` (issued with no work done yet on this basis) or `no_norm` (issued to a line with no norm, so the four worked figures and `variance_pct` are null). `unallocated` lists issues with no BOQ line, and `below_reorder` lists stock under its reorder level. Norms are entered on the website.

## Approvals: a contractor payment ahead of the work

`GET /approvals`, the pull's `approvals` and the reply of `POST /approvals/{id}/act` share one presenter. For `doc_type` `contractor` the `document` carries two additive keys, always present:

- `owner_override`: `{needed: bool, allowed: bool, message: string|null}`, from `PaymentAheadGuard::assess()` while the approval is `IN_REVIEW`.
  - Blocked at the final level: `needed: true`, `allowed: User::isOwner()` (built-in owner role, no custom role), `message: PaymentAheadGuard::blockedMessage(figures, money.view)`, which names no figure without `money.view`.
  - Blocked before the final level: `needed: false`, `allowed: false`, `message` is the warning that the last approval will need the owner. Nothing is refused at this level.
  - Not blocked, or no longer in review: `{needed: false, allowed: false, message: null}`.
- `check`: the guard's figures `{contract_value, work_done, work_pct, paid, paid_pct, paid_after, paid_pct_after, gap_after, limit_pct}` (decimal strings) and `blocked` (bool). `null` without `money.view`, and `null` once the approval is decided.

`POST /approvals/{id}/act` takes an optional `override_reason` (string, at most 1000 characters) and passes it to `ApprovalService::act()` as `['override_reason' => ...]`, the same call the website makes. A final approve the guard blocks answers 422 with `errors.override_reason`: for anyone but the owner the `blockedMessage`; for the owner without a reason of at least 20 characters and 4 words, "Write why this payment may go ahead of the work done, in at least one full sentence." A request without the field behaves as before. A reason sent when none is needed is not stored.
