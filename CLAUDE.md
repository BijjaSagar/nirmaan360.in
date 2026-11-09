# Nirmaan360 (BuildOS)

Multi-tenant construction SaaS for Indian contractors. Live scope is the "site to bill" chain:
BOQ → DPR → measurement book → approvals → RA bills. The product spec is `docs/SRS.md`, which is git-ignored because the repository is public. Never commit it.

## Stack

Laravel 13, PHP 8.3+, Blade, Tailwind 4, Alpine.js, Sanctum for the mobile API, dompdf for bills.
The SRS names NestJS, Next.js and Flutter. This codebase is Laravel because it deploys to Laravel Cloud.
The SRS rules below still apply.

## Rules that must hold

1. Every tenant table has `organization_id` and its model uses `BelongsToOrganization`. Without a signed-in user the scope does not apply, so public and console code must filter by organisation itself.
2. Validate foreign ids against the tenant: use `org_exists('table')` or a project-scoped `Rule::exists`.
3. Money is `decimal(15,2)`, quantity is `decimal(14,3)`. Do arithmetic with `App\Support\Dec`, never PHP floats. Dashboard roll-ups are the only display-only exception.
4. Approved measurements, bills and DPRs are immutable (`Immutable` trait, HTTP 409). Corrections are reversal entries.
5. Mobile and form writes carry `client_uuid`, and creating twice with the same value returns the original row.
6. Permissions are `module.action` strings in `config/buildos.php`, checked with `can:` route middleware.
7. State changes write `audit_logs` through the `Auditable` trait or `AuditLog::record`.
8. Business logic lives in `app/Services`, with controllers limited to validation and redirects.
9. Financial year April to March, timezone Asia/Kolkata, currency INR. UI text goes through `__()` with translations in `lang/mr.json` and `lang/hi.json`.
10. No secrets in code.

## Commands

```
composer install && npm install
php artisan migrate --seed   # demo data, local only
composer dev
php artisan test
npm run build
```

## Definition of done

Billing and measurement changes need service tests. New routes need a feature test covering permission and tenant isolation. Migrations must have a working `down()`.
