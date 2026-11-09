@php
    $inProject = isset($project) && $project instanceof \App\Models\Project && $project->exists;
    $organization = auth()->user()->organization;
    // On the offline plan the website is only account, billing and devices (OfflinePlanGate), so the menu shows just those.
    $offline = app(\App\Services\OfflinePlan\OfflinePlanPolicy::class)->isOffline($organization);
    $nav = fn (string $pattern) => request()->routeIs($pattern) ? 'nav-link active' : 'nav-link';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <title>@yield('title', __('Dashboard')) · {{ config('buildos.brand') }}</title>
</head>
<body class="font-sans antialiased">
<div x-data="{ open: false }" class="min-h-screen lg:flex">
    <div x-show="open" x-cloak x-transition.opacity @click="open = false" class="fixed inset-0 z-30 bg-ink/50 lg:hidden"></div>

    <aside :class="open ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col bg-neel-deep transition-transform lg:static lg:shrink-0 lg:translate-x-0">
        <div class="border-b border-white/10 px-4 py-3.5">
            <a href="{{ route($offline ? 'offline.devices.index' : 'dashboard') }}"><x-brand /></a>
            <p class="mt-2 truncate text-[13px] text-paper/70">{{ $organization->name }}</p>
            <p class="eyebrow mt-0.5 text-[10px] text-haldi">
                {{ __(config('buildos.plans.'.$organization->effectivePlan().'.label')) }}
                @if ($organization->isOnTrial()) · {{ __('Trial to :date', ['date' => $organization->trial_ends_at->format('d M')]) }} @endif
            </p>
        </div>

        <nav class="flex-1 space-y-4 overflow-y-auto px-2.5 py-3">
            @unless ($offline)
                <div class="space-y-0.5">
                    <a href="{{ route('dashboard') }}" class="{{ $nav('dashboard') }}">{{ __('Dashboard') }}</a>
                    @can('project.view')
                        <a href="{{ route('projects.index') }}" class="{{ $nav('projects.index') }}">{{ __('Projects') }}</a>
                    @endcan
                    <a href="{{ route('approvals.index') }}" class="{{ $nav('approvals.*') }} justify-between">
                        <span>{{ __('Approvals') }}</span>
                        @if ($pendingApprovals > 0)
                            <span class="num rounded-[2px] bg-haldi px-1.5 text-[11px] font-medium text-ink">{{ $pendingApprovals }}</span>
                        @endif
                    </a>
                </div>
            @endunless

            @if ($inProject && ! $offline)
                <div>
                    <a href="{{ route('projects.show', $project) }}" class="mb-1 block truncate px-2.5">
                        <span class="eyebrow block text-[10px] text-paper/45">{{ $project->code }}</span>
                        <span class="block truncate font-display text-[17px] font-semibold text-paper">{{ $project->name }}</span>
                    </a>
                    <div class="space-y-0.5">
                        <a href="{{ route('projects.show', $project) }}" class="{{ $nav('projects.show') }}"><span class="nav-no">01</span>{{ __('Overview') }}</a>
                        @can('boq.view')
                            <a href="{{ route('boq.index', $project) }}" class="{{ $nav('boq.*') }}"><span class="nav-no">02</span>{{ __('BOQ & ledger') }}</a>
                        @endcan
                        @if ($organization->allowsFeature('site'))
                            @can('dpr.view')
                                <a href="{{ route('dprs.index', $project) }}" class="{{ $nav('dprs.*') }}"><span class="nav-no">03</span>{{ __('Daily reports') }}</a>
                            @endcan
                            @can('issue.view')
                                <a href="{{ route('issues.index', $project) }}" class="{{ $nav('issues.*') }}"><span class="nav-no">04</span>{{ __('Issues') }}</a>
                            @endcan
                        @endif
                        @can('attendance.view')
                            <a href="{{ route('attendance.index', $project) }}" class="{{ $nav('attendance.*') }}"><span class="nav-no">05</span>{{ __('Attendance') }}</a>
                        @endcan
                        @can('petty_cash.view')
                            <a href="{{ route('petty.index', $project) }}" class="{{ $nav('petty.*') }}"><span class="nav-no">06</span>{{ __('Petty cash') }}</a>
                        @endcan
                        @can('measurement.view')
                            <a href="{{ route('measurements.index', $project) }}" class="{{ $nav('measurements.*') }}"><span class="nav-no">07</span>{{ __('Measurement book') }}</a>
                        @endcan
                        @if ($organization->allowsFeature('billing'))
                            @can('work_order.view')
                                <a href="{{ route('work-orders.index', $project) }}" class="{{ $nav('work-orders.*') }}"><span class="nav-no">08</span>{{ __('Work orders') }}</a>
                            @endcan
                            @can('ra_bill.view')
                                <a href="{{ route('ra-bills.index', $project) }}" class="{{ $nav('ra-bills.*') }}"><span class="nav-no">09</span>{{ __('RA bills') }}</a>
                            @endcan
                        @endif
                        @include('layouts.partials.module-nav', ['place' => 'project', 'project' => $project])
                    </div>
                </div>
            @endif

            <div class="space-y-0.5">
                @include('layouts.partials.module-nav', ['place' => 'company'])
            </div>

            <div class="space-y-0.5">
                <p class="eyebrow px-2.5 pb-1 text-[10px] text-paper/45">{{ __('Company') }}</p>
                @unless ($offline)
                    @can('worker.view')
                        <a href="{{ route('workers.index') }}" class="{{ $nav('workers.*') }}">{{ __('Workers') }}</a>
                    @endcan
                    @if ($organization->allowsFeature('parties'))
                        @can('party.view')
                            <a href="{{ route('parties.index') }}" class="{{ $nav('parties.*') }}">{{ __('Contacts') }}</a>
                        @endcan
                    @endif
                    @can('user.manage')
                        <a href="{{ route('team.index') }}" class="{{ $nav('team.*') }}">{{ __('Team') }}</a>
                    @endcan
                @endunless
                @can('settings.manage')
                    <a href="{{ route('settings.edit') }}" class="{{ $nav('settings.*') }}">{{ __('Settings') }}</a>
                @endcan
                @unless ($offline)
                    @can('audit.view')
                        <a href="{{ route('audit.index') }}" class="{{ $nav('audit.*') }}">{{ __('Audit log') }}</a>
                    @endcan
                @endunless
                @can('billing.manage')
                    <a href="{{ route('billing.index') }}" class="{{ $nav('billing.*') }}">{{ __('Billing') }}</a>
                @endcan
                @if (auth()->user()->isPlatformAdmin())
                    <a href="{{ route('admin.index') }}" class="{{ $nav('admin.*') }}">{{ __('Platform admin') }}</a>
                @endif
            </div>
        </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex items-center justify-between gap-3 border-b border-rule bg-sheet px-4 py-2">
            <button type="button" class="btn btn-ghost lg:hidden" @click="open = true" aria-label="{{ __('Open menu') }}">☰</button>
            <span class="hidden lg:block"></span>
            <div class="flex items-center gap-3">
                <x-locale-switch class="hidden sm:flex" />

                <div x-data="{ show: false }" class="relative">
                    <button type="button" @click="show = !show" class="relative block p-1 text-ink-2" aria-label="{{ __('Notifications') }}">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9zM10 20a2.2 2.2 0 0 0 4 0"/></svg>
                        @if ($unreadNotifications->isNotEmpty())
                            <span class="absolute top-0.5 right-0.5 size-2 rounded-full bg-laterite"></span>
                        @endif
                    </button>
                    <div x-show="show" x-cloak @click.outside="show = false" class="card absolute right-0 z-20 mt-1 w-80 shadow-lg">
                        <div class="card-head">
                            <span class="font-semibold">{{ __('Notifications') }}</span>
                            @if ($unreadNotifications->isNotEmpty())
                                <form method="POST" action="{{ route('notifications.read') }}">@csrf<button class="link text-xs">{{ __('Mark all read') }}</button></form>
                            @endif
                        </div>
                        @forelse ($unreadNotifications as $notification)
                            <div class="border-b border-rule px-4 py-2 text-[13px] last:border-b-0">
                                <p class="font-semibold">{{ $notification->data['title'] ?? '' }}</p>
                                @if (! empty($notification->data['body']))<p class="text-ink-3">{{ $notification->data['body'] }}</p>@endif
                            </div>
                        @empty
                            <p class="px-4 py-4 text-sm text-ink-3">{{ __('Nothing new.') }}</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex items-center gap-2 border-l border-rule pl-3">
                    <div class="hidden text-right leading-tight sm:block">
                        <p class="text-[13px] font-semibold">{{ auth()->user()->name }}</p>
                        <p class="text-[11px] text-ink-3">{{ __(auth()->user()->roleLabel()) }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost">{{ __('Sign out') }}</button></form>
                </div>
            </div>
        </header>

        <main class="flex-1 px-4 py-5 sm:px-6">
            @if (session('success'))
                <div class="mb-4 rounded-[3px] border border-moss bg-moss/10 px-3 py-2 text-sm font-medium text-moss" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">{{ $errors->first() }}</div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
