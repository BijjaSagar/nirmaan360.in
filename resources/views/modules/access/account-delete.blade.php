@extends($party ? 'modules.contractor_portal.layout' : 'layouts.app')
@section('title', __('Delete my account'))
@php
    $company = $preview['company_name'];
    $pending = $preview['pending'];
    $isCompany = $preview['mode'] === 'company';
@endphp
@section('content')
    <x-page-header :title="$isCompany ? __('Delete your account and company') : __('Delete my account')" :subtitle="__('Remove your sign-in and personal details')" />

    @if ($party)
        {{-- The portal frame shows no error banner of its own. --}}
        @foreach (['mode', 'throttle'] as $key)
            @error($key)<p class="mb-4 max-w-2xl rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">{{ $message }}</p>@enderror
        @endforeach
    @endif

    <div class="max-w-2xl space-y-5">
        @if ($pending)
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Your company is set to be erased') }}</h2><span class="stamp border-laterite text-laterite">{{ __('Scheduled') }}</span></div>
                <div class="space-y-3 p-4 text-sm">
                    <p>{{ __(':company will be erased after :date.', ['company' => $company, 'date' => $pending['erase_after']->format('d M Y')]) }}</p>
                    @if ($pending['can_cancel'])
                        {{-- Cancelled here rather than on Company data, which the offline plan does not open. --}}
                        <form method="POST" action="{{ route('account.cancel') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-primary">{{ __('Cancel the deletion') }}</button>
                        </form>
                    @else
                        <p class="text-ink-3">{{ __('Only a company owner can cancel it.') }}</p>
                    @endif
                </div>
            </section>
        @endif

        @unless ($isCompany && $pending)
            <section class="card">
                <div class="space-y-3 p-4 text-sm">
                    @if ($isCompany)
                        <p class="font-semibold">{{ __('You are the only owner of :company, so deleting your account erases the company: every project, bill, photo, worker and user.', ['company' => $company]) }}</p>
                        <p>
                            @if ($preview['other_people'] === 0)
                                {{ __('No one else uses this company.') }}
                            @elseif ($preview['other_people'] === 1)
                                {{ __('1 other person will lose access.') }}
                            @else
                                {{ __(':count other people will lose access.', ['count' => $preview['other_people']]) }}
                            @endif
                        </p>
                        <p>{{ __('It is erased after :date. Until then the company keeps working, and you can cancel by signing in again and opening Account, Delete my account.', ['date' => $preview['erase_after']->format('d M Y')]) }}</p>
                        <p>{{ __('Records of payments your company made to :brand are kept, as Indian tax law requires.', ['brand' => config('buildos.brand')]) }}</p>
                        @unless ($offline)
                            <p>{{ __('Want a copy first? Export it from the Company data page.') }} <a href="{{ route('notify.data.index') }}" class="link">{{ __('Company data') }}</a></p>
                            <p>{{ __('To keep the company going without you, make another member an owner under Team first, then delete your account here.') }} <a href="{{ route('team.index') }}" class="link">{{ __('Team') }}</a></p>
                        @endunless
                    @else
                        <p class="font-semibold">{{ __('Your sign-in, name, email and mobile number are removed now. You cannot undo this.') }}</p>
                        <p>{{ __('Work you recorded for :company — reports, measurements, bills, attendance and photos — stays with the company, shown as "Deleted user".', ['company' => $company]) }}</p>
                        <p>{{ __('You are signed out of the website and the app on every device.') }}</p>
                    @endif
                </div>

                <form method="POST" action="{{ route('account.destroy') }}" autocomplete="off" class="space-y-3 border-t border-rule p-4">
                    @csrf
                    <input type="hidden" name="mode" value="{{ $preview['mode'] }}">
                    @if ($isCompany)
                        <x-field :label="__('Type :name to confirm', ['name' => $company])" name="confirm_name"><input id="confirm_name" name="confirm_name" class="input" autocomplete="off" required></x-field>
                    @endif
                    <x-field :label="__('Your password')" name="password"><input id="password" name="password" type="password" class="input" required autocomplete="current-password"></x-field>
                    <button class="btn btn-danger">{{ __('Delete my account') }}</button>
                </form>
            </section>
        @endunless
    </div>
@endsection
