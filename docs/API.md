# Mobile API

The field app's API lives under `/api/v1` and takes a Sanctum bearer token. The rules every endpoint follows (money and quantity as strings, dates, `client_uuid`, pagination) are in `mobile/docs/CONTRACT.md`. This file adds the endpoints built since then.

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

Unchanged, except that each line gains `tenders`: the marker the web BOQ table shows. It counts tenders that are not cancelled, and only each contractor's latest quote. `lowest_rate` is only there for `money.view`. `tenders` is null for a line with no tender, and on every line for someone who may not see tenders (no `contractor.view`, or a plan without the module).

`tenders` is not in `/sync/pull`, whose `boq_items` keep their shape: a new quote changes the marker without changing the line, so a delta pull would not carry it. Read it from this endpoint while online.

```json
{"data": [{
  "id": 1, "project_id": 1, "section": "Superstructure", "code": "2.1", "description": "RCC M25 in slab", "unit": "cum",
  "qty": "100.000", "rate": "1000.00", "sort": 0, "measured_qty": "0.000", "approved_qty": "0.000", "balance_qty": "100.000",
  "updated_at": "2026-11-10T18:50:19+05:30",
  "tenders": {"tenders": 1, "quotes": 2, "lowest_rate": "900.00"}
}]}
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
