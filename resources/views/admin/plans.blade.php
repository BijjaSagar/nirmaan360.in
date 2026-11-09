@extends('layouts.app')
@section('title', __('Plans and prices'))
@section('content')
    <x-page-header :title="__('Plans and prices')" :subtitle="__('Changes show on the website straight away')" />
    @include('admin._tabs')

    <form method="POST" action="{{ route('admin.plans.update') }}">
        @csrf @method('PATCH')
        <div class="grid gap-5 lg:grid-cols-2">
            @foreach ($plans as $key => $plan)
                <section class="card p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-field :label="__('Plan name')" :name="'plans.'.$key.'.label'"><input name="plans[{{ $key }}][label]" value="{{ old("plans.{$key}.label", $plan['label']) }}" class="input" required></x-field>
                        <x-field :label="__('Short description')" :name="'plans.'.$key.'.tagline'"><input name="plans[{{ $key }}][tagline]" value="{{ old("plans.{$key}.tagline", $plan['tagline']) }}" class="input" required></x-field>
                        @if ($plan['buyable'])
                            <x-field :label="__('Monthly price (₹)')" :hint="plan_gst_note()" :name="'plans.'.$key.'.monthly'"><input name="plans[{{ $key }}][monthly]" type="number" min="1" value="{{ old("plans.{$key}.monthly", $plan['monthly']) }}" class="input num" required></x-field>
                            <x-field :label="__('Yearly price (₹)')" :hint="plan_gst_note()" :name="'plans.'.$key.'.yearly'"><input name="plans[{{ $key }}][yearly]" type="number" min="1" value="{{ old("plans.{$key}.yearly", $plan['yearly']) }}" class="input num" required></x-field>
                        @else
                            <p class="text-sm text-ink-3 sm:col-span-2">{{ $key === 'free' ? __('This plan is always free.') : __('This plan is priced by conversation.') }}</p>
                        @endif
                        <x-field :label="__('Max users')" :name="'plans.'.$key.'.max_users'" :hint="__('Leave blank for unlimited.')"><input name="plans[{{ $key }}][max_users]" type="number" min="1" value="{{ old("plans.{$key}.max_users", $plan['max_users']) }}" class="input num"></x-field>
                        <x-field :label="__('Max projects')" :name="'plans.'.$key.'.max_projects'" :hint="__('Leave blank for unlimited.')"><input name="plans[{{ $key }}][max_projects]" type="number" min="1" value="{{ old("plans.{$key}.max_projects", $plan['max_projects']) }}" class="input num"></x-field>
                    </div>
                    <fieldset class="mt-3">
                        <legend class="label">{{ __('Included modules') }}</legend>
                        <div class="grid gap-1.5 text-sm sm:grid-cols-2">
                            @foreach ($features as $feature => $label)
                                <label class="flex items-center gap-2"><input type="checkbox" name="plans[{{ $key }}][features][]" value="{{ $feature }}" @checked(in_array($feature, old("plans.{$key}.features", $plan['features']), true))> {{ __($label) }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                </section>
            @endforeach
        </div>
        <button class="btn btn-primary mt-5">{{ __('Save plans') }}</button>
    </form>
@endsection
