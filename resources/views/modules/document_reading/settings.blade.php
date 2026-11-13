@extends('layouts.app')
@section('title', __('AI document readers'))
@section('content')
    <x-page-header :title="__('AI document readers')" :subtitle="__('Whether the readers may send your company\'s pages, photos and text to the AI service, and how much each AI feature may spend.')" />

    <div class="max-w-3xl space-y-5">
        @if (! $offered && ! $consent['enabled'])
            <section class="card space-y-2 p-4">
                <p class="text-sm font-semibold">{{ __('No reader is switched on yet.') }}</p>
                <p class="text-sm text-ink-3">{{ __('When a reader is available for your company, the notice to read and the switch appear here. Until then no reader sends anything to the AI service.') }}</p>
            </section>
        @elseif (! $offered)
            {{-- The company agreed while a reader was available. Its agreement stays on record, so it can be switched off here. --}}
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">{{ __('Sending documents to the AI service') }}</h2>
                </div>
                <div class="space-y-3 p-4">
                    <p class="text-sm">{{ __('No reader is available for your company right now, so no reader sends anything to the AI service. Your company\'s agreement stays on record and counts again when a reader is back, unless the notice has changed. Switch the readers off here to withdraw it.') }}</p>
                    @include('modules.document_reading._switch_off')
                </div>
            </section>
        @else
            @if ($settings?->platform_block)
                <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">{{ __('The readers are switched off for your company. Please contact support.') }}</p>
            @endif

            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">{{ __('Sending documents to the AI service') }}</h2>
                    <span class="stamp {{ $consent['current'] ? 'border-moss text-moss' : 'border-ink-3 text-ink-3' }}">{{ $consent['current'] ? __('On') : __('Off') }}</span>
                </div>
                <div class="space-y-3 p-4">
                    <p class="text-sm font-semibold">{{ __('Read this before you switch the readers on.') }}</p>
                    <ul class="list-disc space-y-1.5 pl-5 text-sm">
                        @foreach ($sentences as $sentence)
                            <li>{{ $sentence }}</li>
                        @endforeach
                    </ul>

                    @if ($consent['current'])
                        <p class="text-sm text-ink-3">
                            {{ __('Switched on by :name on :date.', ['name' => $consentedBy?->name ?? __('Deleted user'), 'date' => $settings->consented_at?->format('d M Y, H:i')]) }}
                            {{ __('Notice version: :version.', ['version' => $settings->consent_version]) }}
                        </p>
                    @else
                        @if ($consent['enabled'])
                            <p class="rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm" role="alert">{{ __('This notice has changed since your company agreed to it. Nothing is sent until an owner or admin agrees to it again.') }}</p>
                        @endif

                        <form method="POST" action="{{ route('ai-documents.update') }}" class="space-y-3 border-t border-rule pt-3">
                            @csrf
                            <input type="hidden" name="action" value="on">
                            <input type="hidden" name="notice_locale" value="{{ $noticeLocale }}">
                            <input type="hidden" name="notice_hash" value="{{ $noticeHash }}">
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="accept" value="1" class="mt-0.5" required>
                                <span>{{ __('I have read this notice and agree to it for my company.') }}</span>
                            </label>
                            @error('accept')<p class="error">{{ $message }}</p>@enderror
                            <button class="btn btn-primary">{{ __('Switch the readers on') }}</button>
                        </form>
                    @endif

                    @if ($consent['enabled'])
                        @include('modules.document_reading._switch_off')
                    @endif
                </div>
            </section>
        @endif

        @if ($limits)
            <form method="POST" action="{{ route('ai-documents.update') }}" class="card">
                @csrf
                <input type="hidden" name="action" value="limits">
                <div class="card-head"><h2 class="card-title">{{ __('Spending limits') }}</h2></div>
                <div class="space-y-4 p-4">
                    <p class="text-sm text-ink-3">{{ __('Limits are in AI credits. Leave a box empty to use the usual limit, which is shown in grey. A dash (-) means there is no usual limit.') }}</p>

                    @if ($showReadMaximum)
                        <x-field :label="__('Most credits one document may use')" name="max_credits_per_read" :hint="__('You can lower this for your company. It cannot be above :max credits.', ['max' => $limits['max_credits_per_read']['platform']])">
                            <input id="max_credits_per_read" name="max_credits_per_read" inputmode="decimal" autocomplete="off" class="input num max-w-40" value="{{ old('max_credits_per_read', $limits['max_credits_per_read']['own']) }}" placeholder="{{ $limits['max_credits_per_read']['platform'] }}">
                        </x-field>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="ledger [&_td]:px-2 [&_th]:px-2 sm:[&_td]:px-3 sm:[&_th]:px-3">
                            <thead>
                                <tr>
                                    <th>{{ __('Credits a day') }}</th>
                                    <th class="r">{{ __('Each person') }}</th>
                                    <th class="r">{{ __('Whole company') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($limits['features'] as $feature)
                                <tr>
                                    <td>{{ \Illuminate\Support\Str::ucfirst($feature['label']) }}</td>
                                    @foreach (['user' => __('Each person'), 'company' => __('Whole company')] as $scope => $scopeLabel)
                                        <td class="r">
                                            <input name="budgets[{{ $scope }}][{{ $feature['key'] }}]" inputmode="decimal" autocomplete="off" class="input num w-20 text-right sm:w-24"
                                                aria-label="{{ \Illuminate\Support\Str::ucfirst($feature['label']) }}: {{ $scopeLabel }}"
                                                value="{{ old("budgets.{$scope}.{$feature['key']}", $feature[$scope]['own']) }}"
                                                placeholder="{{ $feature[$scope]['default'] ?? '-' }}">
                                            @error("budgets.{$scope}.{$feature['key']}")<p class="error">{{ $message }}</p>@enderror
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                                <tr>
                                    <td>{{ \Illuminate\Support\Str::ucfirst(\App\Services\DocumentReading\AiSpend::label('total')) }}</td>
                                    <td class="r text-ink-3">-</td>
                                    <td class="r">
                                        <input name="budgets[company][total]" inputmode="decimal" autocomplete="off" class="input num w-20 text-right sm:w-24"
                                            aria-label="{{ \Illuminate\Support\Str::ucfirst(\App\Services\DocumentReading\AiSpend::label('total')) }}: {{ __('Whole company') }}"
                                            value="{{ old('budgets.company.total', $limits['total']['own']) }}"
                                            placeholder="{{ $limits['total']['default'] ?? '-' }}">
                                        @error('budgets.company.total')<p class="error">{{ $message }}</p>@enderror
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="hint">{{ __('A budget counts the credits charged today and the credits on hold. It starts again at midnight. Enter 0 to stop a feature.') }}</p>
                </div>
                <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save limits') }}</button></div>
            </form>
        @endif
    </div>
@endsection
