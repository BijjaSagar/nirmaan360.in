# Running the AI readers

The readers send page pictures or page text to the AI provider, one page per call, and stage what comes back for a person to check. This page is for whoever runs the server. It says what must be running, how to start it, and where to look when a read does not move.

Nothing here needs a new package or a new service. The readers use the queue and the scheduler the application already has.

## What must be running

| What | Why | How often |
| --- | --- | --- |
| A queue worker | Every page is one queued job (`ReadDocumentPage`). Without a worker no page is ever sent. | Always |
| The scheduler | It runs `document-reads:tend`, which recovers pages a worker lost, sends the next page of a read that stalled, closes reads nobody came back to and deletes old files. | Every minute |

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

and then set the queue names in the platform settings (the platform admin's AI reader page has the fields once it is deployed):

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
| A finished read was never confirmed or discarded | `abandon_days` (14) without any change | Closed as expired. |
| A read ended (confirmed, discarded, cancelled, failed, expired) | `keep_days` (30) | Its folder is deleted, and with it every page picture, the original PDF and the extracted text. What the reader returned is blanked. Counts, tokens and costs stay. |

Every pause, cancel, finish and expiry gives back the credits the read had on hold, in the same database transaction.

Tending also runs each class a module lists under `retention` in its `config/modules/<name>.php` (for example the clean-up of old voice note text), and once an hour asks the provider to count the fixed part of each reader's prompt so that quotes use the real figure. Counting spends no tokens and is skipped while no key is saved.

## When a read does not move

1. **Is a worker running?** The oldest queued page should be seconds or a few minutes old. If its age keeps growing, no worker is reading that queue. Check the queue names above first.
2. **Is the scheduler running?** The last tend run should be under two minutes old. `php artisan schedule:list` shows `document-reads:tend`.

   Both figures are shown on the platform admin's AI reader page once that page is deployed. They come from `ReadTending::oldestQueuedSince()` and `ReadTending::lastRun()`.
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

## Figures that belong together

Each reader call has a model, a largest answer (`max_tokens`) and a time limit, in `config/modules/document_reading.php` under `kinds`. They must be changed together: a call has to be able to write its largest answer inside 80 percent of its time limit at `assumed_tokens_per_second`. A test holds this. No time limit may be above 240 seconds; longer answers would need streaming, which is not used.

The speeds, the typical answer sizes and the prompt size in that file are assumptions until the platform admin's dry run has measured them on real documents. Replace them with measured figures before a reader is offered to any company.
