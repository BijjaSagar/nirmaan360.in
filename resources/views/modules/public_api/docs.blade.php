@extends('layouts.app')
@section('title', __('API documentation'))
@section('content')
    @php
        $endpoints = [
            ['GET', '/projects', 'projects:read', 'List projects.', 'status, page, per_page'],
            ['GET', '/projects/{id}', 'projects:read', 'One project.', ''],
            ['GET', '/projects/{id}/boq', 'boq:read', 'BOQ items with executed, measured, approved and billed quantities.', 'page, per_page'],
            ['GET', '/projects/{id}/dprs', 'dpr:read', 'Daily reports for a project, newest first.', 'from, to, status, page, per_page'],
            ['GET', '/dprs/{id}', 'dpr:read', 'One daily report with manpower, machinery and work done.', ''],
            ['GET', '/bills', 'bills:read', 'RA bills, client and subcontractor.', 'project_id, type, status, page, per_page'],
            ['GET', '/bills/{id}', 'bills:read', 'One RA bill with its items and deductions.', ''],
        ];
    @endphp

    <x-page-header :title="__('API documentation')" :subtitle="__('Read-only access to your company data. Version 1.')">
        <a href="{{ route('api-keys.index') }}" class="btn btn-ghost">{{ __('API keys') }}</a>
    </x-page-header>

    <div class="max-w-4xl space-y-6">
        <section class="card p-4">
            <h2 class="card-title mb-2">{{ __('Base address') }}</h2>
            <pre class="num overflow-x-auto rounded-[3px] bg-paper-2/60 p-3 text-sm">{{ $baseUrl }}</pre>
            <p class="mt-2 text-sm text-ink-2">{{ __('Everything is JSON, read-only, and limited to your own company. Money and quantities are text with fixed decimals, for example "1250.50", so no rounding creeps in.') }}</p>
        </section>

        <section class="card p-4">
            <h2 class="card-title mb-2">{{ __('Signing in') }}</h2>
            <p class="mb-2 text-sm text-ink-2">{{ __('Make a key under API keys and send it with every request.') }}</p>
<pre class="num overflow-x-auto rounded-[3px] bg-paper-2/60 p-3 text-sm">curl {{ $baseUrl }}/projects \
  -H "Authorization: Bearer n360_your_key_here"</pre>
            <p class="mt-2 text-sm text-ink-2">{{ __('A key has scopes. It can only call the endpoints its scopes allow, and you can revoke it at any time.') }}</p>
            <table class="ledger mt-3">
                <thead><tr><th>{{ __('Scope') }}</th><th>{{ __('Allows') }}</th></tr></thead>
                <tbody>
                @foreach ($scopes as $scope => $label)
                    <tr><td class="num text-xs">{{ $scope }}</td><td>{{ __($label) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>

        <section class="card p-4">
            <h2 class="card-title mb-2">{{ __('Endpoints') }}</h2>
            <div class="overflow-x-auto">
                <table class="ledger min-w-[640px]">
                    <thead><tr><th></th><th>{{ __('Path') }}</th><th>{{ __('Scope') }}</th><th>{{ __('What it returns') }}</th><th>{{ __('Query') }}</th></tr></thead>
                    <tbody>
                    @foreach ($endpoints as [$method, $path, $scope, $about, $query])
                        <tr>
                            <td class="num text-xs font-semibold">{{ $method }}</td>
                            <td class="num text-xs">{{ $path }}</td>
                            <td class="num text-xs">{{ $scope }}</td>
                            <td>{{ __($about) }}</td>
                            <td class="num text-xs">{{ $query ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-ink-2">{{ __('Lists are paged. Use page and per_page (up to 100, or 500 for the BOQ). The reply has "data" and "meta" with page, per_page, total and last_page.') }}</p>
        </section>

        <section class="card p-4">
            <h2 class="card-title mb-2">{{ __('Example') }}</h2>
<pre class="num overflow-x-auto rounded-[3px] bg-paper-2/60 p-3 text-xs">GET {{ $baseUrl }}/projects/12/boq?per_page=2

{
  "data": [
    {
      "id": 301, "section": "Foundation", "code": "1.01",
      "description": "RCC M25 in footings", "unit": "cum",
      "qty": "120.000", "rate": "6800.00", "amount": "816000.00",
      "executed_qty": "40.000", "measured_qty": "38.500",
      "approved_qty": "30.000", "billed_qty": "30.000", "balance_qty": "81.500"
    }
  ],
  "meta": { "page": 1, "per_page": 2, "total": 48, "last_page": 24 }
}</pre>
        </section>

        <section class="card p-4">
            <h2 class="card-title mb-2">{{ __('Rate limit') }}</h2>
            <p class="text-sm text-ink-2">{{ __('Each key has a limit of requests a minute, :default unless you chose another when you made it. Every reply carries X-RateLimit-Limit and X-RateLimit-Remaining. Over the limit you get 429 and a Retry-After header with the seconds to wait.', ['default' => $defaultRate]) }}</p>
        </section>

        <section class="card p-4">
            <h2 class="card-title mb-2">{{ __('Errors') }}</h2>
            <table class="ledger">
                <thead><tr><th>HTTP</th><th>code</th><th>{{ __('Meaning') }}</th></tr></thead>
                <tbody>
                <tr><td class="num">401</td><td class="num text-xs">invalid_api_key</td><td>{{ __('The key is missing, wrong, revoked or expired.') }}</td></tr>
                <tr><td class="num">403</td><td class="num text-xs">insufficient_scope</td><td>{{ __('The key does not have the scope this endpoint needs.') }}</td></tr>
                <tr><td class="num">403</td><td class="num text-xs">plan_not_included</td><td>{{ __('Your company plan does not include the public API.') }}</td></tr>
                <tr><td class="num">404</td><td class="num text-xs">—</td><td>{{ __('It does not exist, or it belongs to another company.') }}</td></tr>
                <tr><td class="num">422</td><td class="num text-xs">—</td><td>{{ __('A query value is not valid.') }}</td></tr>
                <tr><td class="num">429</td><td class="num text-xs">rate_limited</td><td>{{ __('Too many requests. Wait for Retry-After seconds.') }}</td></tr>
                </tbody>
            </table>
<pre class="num mt-3 overflow-x-auto rounded-[3px] bg-paper-2/60 p-3 text-xs">{ "error": { "code": "insufficient_scope", "message": "This key does not have the boq:read scope." } }</pre>
        </section>
    </div>
@endsection
