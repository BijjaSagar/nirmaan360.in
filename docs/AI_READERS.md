# Running the AI readers

The readers send page pictures or page text to the AI provider, one page per call, and stage what comes back for a person to check. This page is for whoever runs the server. It says what must be running, how to start it, and where to look when a read does not move.

Nothing here needs a new package or a new service. The readers use the queue and the scheduler the application already has.

## What must be running

| What | Why | How often |
| --- | --- | --- |
| A queue worker | Every page is one queued job (`ReadDocumentPage`), and so is every text call (`RunTextCall`). Without a worker nothing is ever sent. | Always |
| The scheduler | It runs `document-reads:tend`, which recovers pages a worker lost, sends the next page of a read that stalled, closes reads nobody came back to, ends text calls nobody picked up and deletes old files. | Every minute |

If the scheduler is down, a read that someone is watching still moves, because every status request tends that one read. A read nobody is watching does not. So both must run.

## The queue worker

With the default settings every reader job goes to the queue the worker already reads, so the worker you have is enough:

```
php artisan queue:work --tries=3
```

Things to know about it:

- **One call can take up to 240 seconds.** The job allows itself 270 seconds (`ReadDocumentPage::$timeout`), which overrides the worker's `--timeout`. The worker needs the `pcntl` extension for any timeout to work.
- **Leave `retry_after` alone.** It is 90 seconds (`DB_QUEUE_RETRY_AFTER`), shorter than a long call, so the queue may hand a running job to a second worker. That is expected and safe: a page is claimed by one worker, and the second finds it claimed and stops at once. Only the worker that claimed a page can save or charge it.
- **One page of a read at a time.** A read never has more than `modules.document_reading.parallel_pages` pages queued or with the provider (1 by default). When a page ends, the same job hands the next one to the queue. Several reads share the queue in turn.
- **Restart workers after a deploy** (`php artisan queue:restart`), as for any queued code.

### Giving slips and bills their own lane

A slip or a bill is read while a person waits at a form. On one shared queue it can sit behind a BOQ page for up to four minutes. To avoid that, start the worker with the lanes in this order:

```
php artisan queue:work --queue=ai-interactive,default,ai-pages,ai-prepare --tries=3
```

and then set the queue names on the platform admin's AI reader page (`/admin/ai-reader`, **Queue names**), which saves these platform settings:

| Platform setting | Lane | Used by | Suggested name |
| --- | --- | --- | --- |
| `ai_queue_interactive` | interactive | slip pages, bill pages, text calls | `ai-interactive` |
| `ai_queue_pages` | pages | BOQ pages, tender pages | `ai-pages` |
| `ai_queue_prepare` | prepare | taking the text out of a tender PDF | `ai-prepare` |

**Set a name only after a worker listens to it.** A job sent to a queue with no worker never runs. Clearing a setting sends that lane back to the worker's own queue. A name may hold letters, digits, `-`, `_`, `.` and `:`; anything else is ignored and the lane stays where it was.

`default` in the worker command stands for the worker's own queue. If the host has renamed it (`DB_QUEUE`, `REDIS_QUEUE`), use that name there instead.

## The scheduler

The schedule is in `routes/console.php`. Run it every minute, either with cron:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

or with the host's own scheduler, if it has one. `document-reads:tend` is set to run every minute, on one server, and never twice at once.

To run one round by hand:

```
php artisan document-reads:tend
```

It prints how many reads it looked at, how many reads had their files deleted, and how many other old records were cleared.

## What tending does

| Situation | After | What happens |
| --- | --- | --- |
| A page is marked as running but its worker is gone | 330 seconds | It is queued once more by itself. If that worker is lost too, the page fails as `worker_lost`. Nothing is charged. |
| A page has sat in the queue | 5 minutes | Its job is handed to the queue once more, in case the first was lost. A second job does no harm. |
| A running read has no page queued or running | 30 seconds | Its next page is queued, or the read is finished if none is left. |
| Nothing has moved on a running read | 30 minutes | The read is paused (`infra`) and its starter is told. This is what a missing worker looks like. |
| A read is still taking the text out of a PDF | 240 seconds | The read fails with "This file is too large to read as text. Read it as page pictures." |
| A draft was never started | 2 hours without any change | Cancelled. |
| A quote was never accepted | 24 hours | Cancelled. |
| A read is paused | 24 hours | Cancelled. |
| A finished read was never confirmed or discarded | `abandon_days` (14) without any change; a person working on its rows or facts counts as a change | Closed as expired. |
| A read ended (confirmed, discarded, cancelled, failed, expired) | `keep_days` (30) | Its folder is deleted, and with it every page picture, the original PDF and the extracted text. What the reader returned is blanked. Counts, tokens and costs stay. |

Every pause, cancel, finish and expiry gives back the credits the read had on hold, in the same database transaction.

Text calls are looked after in the same round (see "Text calls" below).

Tending also runs each class a module lists under `retention` in its `config/modules/<name>.php` (for example the clean-up of old voice note text), and once an hour asks the provider to count the fixed part of each reader's prompt so that quotes use the real figure. Counting spends no tokens and is skipped while no key is saved.

## When a read does not move

1. **Is a worker running?** The oldest queued page should be seconds or a few minutes old. If its age keeps growing, no worker is reading that queue. Check the queue names above first.
2. **Is the scheduler running?** The last tend run should be under two minutes old. `php artisan schedule:list` shows `document-reads:tend`.

   Both figures are shown on the platform admin's AI reader page (`/admin/ai-reader`), with a warning when either is late. They come from `ReadTending::oldestQueuedSince()`, `TextCallRunner::oldestQueuedSince()`, `TrialRunner::oldestQueuedSince()` and `ReadTending::lastRun()`.
3. **Why did a read pause?** The read's `pause_reason` says:

| `pause_reason` | Meaning | Who can fix it |
| --- | --- | --- |
| `credit_cap` | The read used up the limit it started with. | The person continues it with a new limit. |
| `wallet` | The company has no credits left. | The company buys credits. |
| `starter_gone` | The person who started it is inactive, was taken off the project, or lost the permission. | Someone else starts the read again. |
| `consent_off` | The company's consent is off, or was given to an older notice. | An owner or admin of the company. |
| `plan` | The company's plan no longer includes the AI features. | The company. |
| `many_failures` | Too many pages came back unusable. | The person checks the pages and may continue once. |
| `company_limit` | One of the company's own limits for the day, or its trial allowance. | Wait, or the platform admin. |
| `platform_ceiling` | The platform's spending limit for the day or the month. | The platform admin. |
| `provider_limit` | The provider kept answering "too many requests". | Continue a little later. |
| `not_connected`, `rejected_key`, `billing`, `spend_limit` | No key is saved, or the provider refuses every call. | The platform admin, in the provider's console and on the admin page. |
| `not_released` | The readers were switched off again on the admin page. | The platform admin. |
| `infra` | Calls kept timing out, or nothing moved for 30 minutes. | Check the worker, then continue the read. |

When the provider refuses a call because of the key, the billing or a spending limit, the readers stop sending for 15 minutes so no further call is wasted (the "provider stop"). A spending limit stops them until the platform admin clears it. Platform admins get a notification; companies are only told that their read paused.

## Where files are kept

On the default private disk (`FILESYSTEM_DISK`), which is a bucket in production:

```
org-{company id}/document-reads/{read id}/p{page}-{pass}-{part}.jpg
org-{company id}/document-reads/{read id}/source.pdf
org-{company id}/document-reads/{read id}/text.json
```

A page picture is always the server's own re-drawn JPEG. It is turned into base64 in memory inside the job, and is never written to a job payload, a database column or a log line.

When a company is erased, every file a table row names is deleted first, and then the whole of `org-{company id}/document-reads` is deleted, so a page that was stored while its row could not be saved goes too. That folder is listed under `purge_directories` in `config/modules/document_reading.php`. Any module can list its own folders there in its own config file; `{org}` stands for the company's id and must be part of every entry, and an entry without it is ignored.

## The company's own switch

An owner or admin of a company on a plan with the AI features decides under **AI > AI document readers** (`/settings/ai-documents`) whether the readers may send the company's pages, photos and text to the AI provider at all. The page shows the notice, and the switch is on only after the box under it was ticked. The words that were shown, their language and the notice's version (`AiDocumentConsent::VERSION`) are kept on the company's row in `ai_document_settings`; the audit trail keeps the version, the language and a fingerprint of the words.

- The switch and the notice appear only when a reader is deployed and the readers are released. Until then the page says "No reader is switched on yet." and nothing can be agreed to.
- Switching off cancels every read of the company that is not finished and gives back its credits on hold. Reads that were already finished can still be checked and saved.
- A new wording of the notice is a new version. From the moment it is deployed the readers, and the text calls under the switch, send nothing for a company until an owner or admin has agreed to it again, and reads started under the old one stop at their next page. The page then says the readers send nothing until then; the other AI features named in the notice keep working, as they do while the switch is off.
- The sentence about voice notes is shown only after the platform setting `ai_voice_accepted` is `1`, which records that speech was shown to stay on the phone.
- The switch covers the readers and the text calls that send what a person wrote or spoke (voice reports, hindrance grouping: `AiDocumentConsent::TEXT_PURPOSES`). The notice says what it does not cover: rate suggestions, translations of quotation lines, and the sentences the AI writes for letters, quotations, weekly updates and bill checks. Those send line descriptions and labels, never a rate, a quantity or an amount of a record, and a test fails if a text purpose outside the switch is not named in the notice (`ai-docs-v3`).
- The same page holds the company's own limits: the most one document may use (it can be lowered, never raised above `max_credits_per_read`) and the daily budgets for each person and for the company, for each feature that is deployed.

### The privacy policy and the terms

The legal pages are in English and have one address each (`App\Services\Seo\PublicPages`). Their paragraphs about the AI provider have Hindi and Marathi lines in `lang/modules/document_reading`, but those lines are used only once a reader is offered (deployed and released). Until then every paragraph of both pages is English for every visitor, whatever their language, so no unchecked translation reaches a live legal page. The paragraphs shown only before a reader is offered are therefore plain English with no Hindi or Marathi line. This differs from the spec, which lists Hindi and Marathi for all of the legal text.

The policy names the other AI features with what they send (rate suggestions, translations of quotation lines, sentences for letters, quotations, weekly updates and bill checks) in the same branch as the readers: they work only while the readers are released, and a test fails if a route that makes a text call is not named there.

Before the readers are released on production, a native reader must check the Hindi and Marathi lines of the notice and of these paragraphs (spec E9). Releasing is what switches those lines on.

## Which routes are guarded

`App\Http\Middleware\RequireAiReader` goes on every route that makes the AI model read or write something, and on no other. A route that sends a page, a photo, document text, spoken words or notes a person wrote uses `RequireAiReader::class.':consent'`; one that sends line descriptions and labels, never a rate, a quantity or an amount, uses `':text'`, which the notice names as not under the company's switch. Looking at a read, correcting it, confirming it, its status and its page pictures carry no guard, so a company can always finish what was already read. A test fails if a `GET` route carries it.

| Answer | Code | When |
| --- | --- | --- |
| 409 | `not_released` | The readers are not released. |
| 409 | `not_connected` | No key is saved, or the provider stop above is standing. |
| 403 | | The company's plan has no AI features. |
| 409 | `company_limit` with `limit: blocked` | The platform admin has switched the readers off for this company. |
| 409 | `consent_required` | `:consent` only: the company has not agreed to the current notice. |
| 409 | `reader_paused` with `period: daily` or `monthly` | A platform spending limit is reached. |

The phone and any request that asks for JSON get the code with a sentence in `message`. A browser is sent back to the page it came from with the sentence, and for a missing consent an owner or admin is taken to the notice. Budgets, the wallet and a company's own brakes are checked later, where credits are put on hold, and answer as `SpendRefused` says.

### How the day's and the month's figures are counted

Every call for a page that cost something, was charged something or came back unusable leaves one row in `document_read_attempts`, dated by when that call ended and booked to the person the read was booked to at that moment. A page row adds up all of its calls for the read's own totals, and a page can be read again on a later day, so the limits never sum page rows: the daily budgets, the company's share of the day, its cap on cost nobody was charged for, the brake on unusable answers and the platform's day and month all count these rows. A page read again today therefore brings none of yesterday's cost, charge or unusable answer into today, and an answer forgiven when the platform admin lifts the brake stays forgiven when its page is read again.

A text call is counted on the day it was made. Its `absorbed_usd` holds what nobody paid for: every attempt whose answer was not kept, and the part of the kept one that its maximum or the wallet did not cover.

A daily budget belongs to the person the read is booked to. When an owner or admin starts or continues one person's own read (a slip or a bill), the read stays booked to that person, so that person's budget is the one checked. When it is used up, the refusal names that person, so the owner or admin is not told they used their own budget.

## The platform admin's AI reader page

`/admin/ai-reader` is for platform staff only (`EnsurePlatformAdmin`; everyone else gets a 404). It shows whether a worker and the scheduler are alive, the provider stop with a button to clear it, the month's spending against the provider's cap, today's spending against the daily limit and against the dry runs' own allowance, the queue names, what each reader runs with and what was measured, use by company this month with the trial allowance lift and the per-company block, and the browser self-check. Every change it makes is written to the audit trail with no company (`PlatformAudit`), so no company's own audit screen shows it.

### Spending limits

| Field | Platform setting | Default |
| --- | --- | --- |
| Provider's monthly cap (US$) | `ai_tier_monthly_cap_usd` | none: copy it from the provider's console |
| Share of the cap the month may reach | `ai_reader_month_share` | `ceilings.month_share` |
| Daily limit for all companies (US$) | `ai_reader_daily_usd` | `ceilings.daily_usd` |
| Daily allowance for dry runs (US$) | `ai_trial_daily_usd` | `ceilings.trial_daily_usd`; zero switches dry runs off |

The month counts agents, readers, text calls and dry runs. The cap cannot be removed while the release switch is on.

### The release switch

`ai_readers_released` is off until a platform admin turns it on here, and the page turns it on only with the monthly cap entered, the tick that spend alerts are set in the provider's console, and a tick for every deployed reader and every text call that can be tried on pasted text that its dry run was done. Switching it off stops new reads at once and pauses running ones before their next page.

Whether a company is offered a reader is `AiSpend::readersOn()`, the one test the website, the consent switch, the menu, the privacy and terms pages and the phone's status answer ask: the switch on, the cap on record, a key saved, and a reader deployed for the company's plan. The page shows that test's answer in its header and says which part is missing. The switch is one for all readers, so a reader deployed while it is on is offered at once by that test. The page then names it as "deployed after the release, with no dry run confirmed" until the switch is turned off and on again with its tick. To dry-run a new reader on production before any company sees it, switch the readers off before deploying it, do its dry run, and switch them on again with its tick.

The readers deployed are the scanned BOQ (`boq`, config/modules/boq_scan.php), the challan and weighbridge slip and the supplier bill (`slip` and `bill`, config/modules/slip_reading.php) and the tender reader (`tender`, config/modules/tender_reading.php), each listed under `read_kinds` in its module's config file, and voice once its acceptance on real phones is recorded (`ai_voice_accepted`). Each needs its dry run ticked before the switch can be turned on. While the readers are off for a company, nothing offers a reader and the BOQ scan screen (`boq-scans.create`) answers 404.

### Dry runs

A dry run tries one reader pass, or one text call, on a document uploaded on this page, with the model, effort, output limit and time limit chosen there. It belongs to no company: `reader_trials` and `reader_trial_pages` have no `organization_id`, the files are under `platform/reader-trials/{uuid}/` on the default disk, nothing is created in any company and nobody is charged. It needs the tick "I am allowed to use this document for testing". PDFs are drawn in the browser as for a company's read and each page picture is redrawn by the server (`PageImage::normalise`).

The call goes through the same steps as a company's (`PageCall::run` or `TextCall::run` with the chosen figures), one page at a time on the queue the same kind of company call would use (`ReadTrialPage`), with the same claim by `attempt_uuid`. A timeout, an outage or a lost worker is sent again on the next Start; an answer that could not be used is a measurement and is not. Its cost counts toward `ai_trial_daily_usd` and the month, never toward the companies' daily limit (`AiSpend::trialStop`); the platform admins are told at the notice steps of the allowance. Each call writes `ai.trial_page_sent` with the picture's fingerprint, size, model and request id, never its content.

The result page shows the picture beside every cell as printed, how the code reads it, the script of its digits, the kind's own checks, tokens, output tokens a second, cost, seconds, stop reason and request id. Clicking a cell marks it wrong; the trial keeps counts per pass and field and per digit script, downloadable as CSV. After 24 hours tending deletes the files, the answers and the marks (`TrialRetention`); counts, tokens and cost stay.

What a feature adds for its dry run:

- A reader kind may implement `Contracts\DescribesTrialResult`: `cellKinds($pass)` names how each field is read (qty, rate, amount, percent, count, date or unit), and `pageChecks($answers)` returns the kind's checks by code over the passes read on a page (for the BOQ reader, quantity times rate against the second pass's amount). Without it the cells are shown as printed and no check is shown.
- A text call handler is offered for a dry run only when it implements `Contracts\TriesPastedText`: `onPasted($text)` returns a twin that builds the same request from the pasted text alone and applies the same checks. Its `done()` and `failed()` are never called.

## Figures that belong together

Each reader call has a model, a largest answer (`max_tokens`) and a time limit, in `config/modules/document_reading.php` under `kinds`. They must be changed together: a call has to be able to write its largest answer inside 80 percent of its time limit at `assumed_tokens_per_second`. A test holds this. No time limit may be above 240 seconds; longer answers would need streaming, which is not used.

The speeds, the typical answer sizes and the prompt size in that file are assumptions until the platform admin's dry run has measured them on real documents. Replace them with measured figures before a reader is offered to any company.

Text calls have the same three figures under `purposes`. Their time limit may not be above 90 seconds: the job that makes a text call is stopped at 110 (`RunTextCall::$timeout`).

## Text calls

A text call sends text and gets a small answer back: the free sentences of a letter, a ranking of rate candidates, a grouping of hindrance notes, a spoken report turned into fields. It is one row in `ai_text_calls` and one queued job. The row says who asked, for which record, what it cost and how it ended. It holds neither what was sent nor what came back: the request is built again from the records each time it is sent, and the answer goes straight to the feature.

How one runs:

1. A feature calls `TextCalls::start($purpose, $record, $person)`, or `WordingService::draft(...)` for the free sentences of a document. Everything that could stop the call is checked first: the permission and the plan, the company's switch for purposes that send a person's own words, the provider stop, the platform and company limits, the daily budgets and the wallet. The request is built once to see that it may be sent and how long it is.
2. The call is made with its maximum (`max_credits`: every character priced as a token, plus the most the model may write). While it is `queued` or `running` that much of the wallet is held back.
3. The job claims the call, passes the same gates again, sends one request and judges the answer: it must be JSON in the form that was asked for.
4. If the feature's own checks refuse the answer (a sentence that states a figure, for instance), nothing is charged and the request is sent once more, saying which rule was broken. If that answer is refused too, the call ends as `rejected`.
5. An accepted answer is handed to the feature and charged, in one transaction, at what it cost and never above the maximum or what the wallet has. Anything else ends the call as `failed` with a `fail_reason`, charges nothing, and tells the feature, which puts its own fallback in place (the code wording, the order by score).

Things to know:

- **No tools, no pictures.** A text call body may hold a model, an output limit, the fixed instructions, one user message of plain text and the form of the answer. Anything else stops the call before it is sent (`TextRequest::problems`), and so does a request longer than `text_call_max_chars` (40,000 characters).
- **Personal details are taken out** of what every purpose sends (phone numbers, e-mail addresses, GSTINs, PANs, account numbers), except a purpose whose figures say `'redact' => false`. Only the spoken report does: it is sent as it was heard, and only under the company's switch.
- **Busy provider.** "Too many requests" and an outage are tried again after a short wait, three attempts in all. A rejected key, a billing problem or a spending limit sets the provider stop, as for pages; the company is only told that the service could not be reached.
- **The corrective request counts as an attempt** on the same row. `cost_usd` and the token columns add up over the attempts; `credits` is what the company paid, which is for one answer at most, and `absorbed_usd` what nobody paid. `correction` keeps which rules the refused answer broke, as codes.

What tending does for text calls:

| Situation | After | What happens |
| --- | --- | --- |
| A call is marked as running but its worker is gone | 150 seconds | It ends as `worker_lost`. Nothing is charged and the feature's fallback stands. |
| A call has sat in the queue | 2 minutes, and every 2 minutes after | Its job is handed to the queue again. A second job does no harm: the call is claimed by one worker. |
| Nobody has picked a call up | 15 minutes after it was made | It ends as `not_started`, so it stops holding credits. This is what a missing worker looks like for text calls. |

A feature's status request calls `TextCalls::watch($call)`, which does the same for that one call, so a call someone is waiting for comes to an end even if the scheduler is down. `TextCallRunner::oldestQueuedSince()` gives the age of the oldest waiting call for the admin page.

Why a call ended without an answer, in `fail_reason`:

| `fail_reason` | Meaning |
| --- | --- |
| `rejected` | The feature's checks refused the answer twice. The status is `rejected` too, and it counts toward the company's daily limit of unusable answers. |
| `invalid_output`, `cut_off`, `declined` | The model answered, but not in the form, or ran into its output limit, or declined. |
| `timeout`, `unavailable` | The provider did not answer in time, or was busy or down on every attempt. A timeout is priced as the most the call could have cost and counted as cost the company caused without paying. |
| `bad_request` | The provider would not take the request. Its own words are kept for the platform admin (`TextCallRunner::rejectionNote`), never on the company's row. |
| `not_connected` | No key is saved, or the provider stop is standing. Which of the provider's reasons it was is not written on the company's row or in its audit trail. |
| `starter_gone`, `consent_off`, `plan`, `not_released`, `platform_ceiling`, `company_limit` | A gate at the claim, as for a page. Nothing was sent. |
| `subject_gone`, `subject_changed` | The record the call was about is gone, or changed so much while the model was writing that the answer no longer fits. |
| `worker_lost`, `not_started` | Found by tending, as in the table above. |
| `internal_error` | Our own code failed: the handler built a request that may not be sent, or could not keep the answer. The error is in the log. |

`TextCallWords::failure($reason)` gives the sentence a person is shown, in their language.

## The shared read routes

Each feature creates its reads through its own route (`ReadDesk::open`). Everything after that is the same for every kind and lives in `routes/modules/document_reading.php` and, under `/api/v1`, in `routes/api_modules/document_reading.php`. They answer JSON on the web as well, because the capture script calls them.

| Route | What it does | Guarded |
| --- | --- | --- |
| `POST /document-reads/{read}/pages` | Stores one page picture, or one half of a cut-off page. The server redraws it before storing. | yes |
| `PUT /document-reads/{read}/source` | Keeps the original PDF for the record. Only kinds marked `keeps_source`. | yes |
| `POST /document-reads/{read}/quote` | The estimate and the limit for the waiting pages. | yes |
| `POST /document-reads/{read}/start` | Holds the limit and queues the first page. | yes |
| `POST /document-reads/{read}/resume` | Continues a paused read. | yes |
| `POST /document-reads/{read}/pages/{page}/retry` | Reads one page again. | yes |
| `POST /document-reads/{read}/pages/{page}/split` | Reads a cut-off page as its two uploaded halves. | yes |
| `GET /document-reads/{read}/status` (API: `GET /document-reads/{read}`) | Where the read stands. Asking also tends the read. | no |
| `POST /document-reads/{read}/cancel` | Cancels a read that is not finished. | no |
| `GET /document-reads/{read}/pages/{page}/image` | The stored page picture. | no |
| `GET /api/v1/document-reading/status` | For the app: released, connected, plan, consent, and what this person may use. | no |

"Guarded" means `RequireAiReader:consent` and the permission `agents.use`. Who may open a read at all is decided for each read by `ReadAccess::assert`: the read's company and project, for a kind that implements `ChecksSubjectAccess` whether the person still sees what it reads (a tender read is not found by someone limited to other projects once its tender is taken into a project), the permissions its kind asks for, the plan feature named under `kinds.{kind}.feature`, and, for kinds marked `own_only`, being the person who started it or an owner or admin.

A read's limit is set at the start. Continuing, reading a page again and reading halves use what is left of it; when that is not enough the answer is `409 limit_reached` with the amounts, and only a request that names a new `cap` changes the limit.

Upload limits to check on the host: a page request may be 6 MB and the original PDF 10 MB (`upload` in the config), so PHP's `upload_max_filesize` and `post_max_size` and the web server's body limit must allow at least 11 MB. The server needs PHP's GD with JPEG, PNG and WebP; without it every upload answers `409 server_cannot_process` and the platform admins are told once a day.

## Drawing pages in the browser

A PDF is never sent to the reader. `resources/js/reader/capture.js` draws each page in the person's browser with pdf.js, at the largest size the reader takes as sent (an A4 page is 1622 by 2296), and uploads the pictures one page per request. A screen gets it by including `modules.document_reading._capture`.

pdf.js fetches part of what it needs while it runs: the decoders for fax, JBIG2 and JPEG 2000 pictures (the ways office scanners store a page), the character maps, the standard fonts and the colour profile. Without them such scans draw as blank pages. So:

- `npm run build` copies those folders from `node_modules/pdfjs-dist` to `public/pdfjs-assets/<version>/` (the small plugin at the top of `vite.config.js`). The folder is not committed. **A deploy that does not run the build, or does not serve `public/pdfjs-assets` as static files, breaks scanned PDFs.**
- `pdfjs_assets` in `config/modules/document_reading.php` names the folder. A test fails when it is not the installed pdfjs-dist version, so an upgrade of the package cannot leave it pointing at a folder that is gone.
- The script never uploads a scan it could not draw: a drawn page that is flat although it holds a picture is reported as "could not be drawn in this browser", and a page with nothing on it is left out unless the person includes it.
- A refusal of the module's own reaches the screen with its code and its sentence in the person's language. Any other failure (a proxy's error page, an expired session, a throttle, a plain permission refusal, a server error) carries the code `http_<status>` and one of the partial's own sentences instead of the framework's English; after the last try at uploading a page it is "Page :n could not be uploaded". This handling is `resources/js/reader/errors.js`, which runs without a browser and is tested with Node.

### The browser self-check

`ReaderCapture.selfCheck({name: address})` runs in the browser and returns `{ok, sizes, fixtures}`: the published page sizes worked out by the script, and one scanned test page of each kind drawn and checked for content. The four test PDFs are in `resources/reader-fixtures` (`ReaderFixtures::names()` and `::path()`); their content is invented, and `make_fixtures.py` beside them is how they were made. They are outside `public/`, so only a route for the platform admin serves them. Run the self-check after every upgrade of pdfjs-dist and on each browser the customers use; it must be green before a reader is released.
