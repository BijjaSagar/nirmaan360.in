# Nirmaan360

Construction software for Indian contractors. BOQ, daily reports, measurement book and RA bills on one record.

This repository covers the foundation and most of the first release, "Site to Bill".

## What is built

- Companies with separate data, plans, roles and `module.action` permissions
- Email and password sign-in, with mobile OTP ready for an SMS provider
- Projects, sites and BOQ with CSV import and export, plus a per-line ledger
- Daily progress reports with photos, issues, labour attendance and petty cash
- Measurement book: Nos × L × B × D, deductions, deviation flags, reversal-only corrections
- Configurable multi-level approvals with an audit trail
- Work orders and client and subcontractor RA bills, with PDF download
- Client sign-off through a signed link
- REST API with offline sync for measurements (`/api/v1`)
- Public website and free calculators in English, Marathi (`/mr`) and Hindi (`/hi`)
- Search engine tags, `/sitemap.xml`, `/robots.txt` and Google Analytics 4 behind a consent question, all set from `/admin/seo`
- Four AI agents on the Business and Enterprise plans, paid for with AI credits: Site Reporter (a draft daily report from a typed site message), Measurement and Billing (draft RA bills from approved measurements), Project Brain (answers from a project's records, with links) and Owner's Copilot (a morning brief). An agent advises, saves a draft, or acts after a person approves; none acts unsupervised. They report "not connected" until an Anthropic API key is saved at `/admin/ai-agents`
- The website describes AI from one class, `App\Services\Website\AiOffer`: the agents are named as live and as part of a plan only while that key is saved, and a reader or helper gets a card only once its route is deployed and, where it needs the AI model, released by the platform admin

## Not built yet

Procurement and stock, Tally sync, GST reports and e-invoicing, WhatsApp, the Flutter field app, AI takeoff, Gantt planning, assets and QHS. Payments are limited to marking a bill paid. OTP sign-in needs a real SMS driver in `app/Services/SmsGateway.php`, and stays hidden in production until then.

## Run locally

Needs PHP 8.4+, Composer and Node.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run build
php artisan serve
```

The seed adds a sample company. Demo users are listed in `database/seeders/DemoSeeder.php`.

```bash
php artisan test
```

## Deploy on Laravel Cloud

1. Connect this repository and the `main` branch to a Laravel Cloud application.
2. Use PHP 8.4 or newer.
3. Attach a PostgreSQL database and a cache.
4. Attach an object storage bucket and make it the default disk. Without one, uploaded photos and bills are lost on each deploy.
5. Build commands: `composer install --no-dev --optimize-autoloader`, then `npm ci && npm run build`.
6. Deploy command: `php artisan migrate --force`.
7. Set `APP_NAME=Nirmaan360`, `APP_ENV=production`, `APP_DEBUG=false` and `APP_URL=https://nirmaan360.in`.
8. Add `nirmaan360.in` as a custom domain and create the DNS records Laravel Cloud shows.

## Going live with subscriptions

Set these in Laravel Cloud's environment variables:

- `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET`. Until both are set, plans can't be bought online.
- `RAZORPAY_WEBHOOK_SECRET`, with a Razorpay webhook pointing at `https://nirmaan360.in/api/v1/webhooks/razorpay` for the `payment.captured` event.
- `PLATFORM_ADMIN_EMAILS`, a comma-separated list of emails that can open `/admin`. A listed email counts only when the account's stored email is exactly that address in lower case; for any other account run `php artisan platform:admin <email>`, which sets the admin flag and accepts any letter case. Nobody can sign up or be invited with a listed email.
- A real mail driver (`MAIL_MAILER` and its settings). Password reset stays hidden in production until this is set.

Plan prices live in `config/buildos.php`. Plans are prepaid per month or year and do not renew by themselves.

## App review demo company

Apple and Google review the field app with a demo login. Make its company once, from the production console:

```bash
php artisan review:demo-company <owner email> --contractor=<contractor email>
```

It refuses, changing nothing, if either email already has an account in any letter case, is a platform admin address or ends in `.invalid`, or the two are the same. It also refuses while any mailer that `MAIL_MAILER` can hand mail to would keep it instead of sending it: the `log` or `array` transport, a `failover` or `roundrobin` mailer that reaches one, or a mailer that is not defined. The log transport would write the password link into the log.

Otherwise it makes a separate company on the Business plan with no end date, fills it with the sample data, and switches every AI agent and the daily brief off. Then it adds what the app's Business screens and the contractor portal read on the first sample project: an approved subcontractor bill and a payment, the standard checklists with one inspection, a machine with a day's log, five floors with progress, and a cement delivery. It posts one announcement, invites the contractor to the portal for the sample subcontractor, and sends the owner the "Forgot password?" email. Both people set their own passwords from those emails; the command never prints a password, a token or a link. A step that fails once the company exists is printed as a warning with the way to do it on the website, and the company id and the emails sent are still printed.

Before a review, `php artisan review:demo-company <owner email> --top-up` makes sure a daily report waits at the project manager and a measurement waits at the QS. It works only on a company this command made.
