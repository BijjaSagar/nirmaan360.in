@extends('layouts.app')
@section('title', __('Sales setup'))
@section('content')
    @php
        $tabs = ['templates' => __('Message templates'), 'reasons' => __('Win and loss reasons'), 'fields' => __('Custom fields')];
    @endphp
    <x-page-header :title="__('Sales setup')" :subtitle="__('Messages, reasons and fields for your company')" />

    <div class="-mx-4 mb-4 flex gap-1.5 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('crm.setup', ['tab' => $key]) }}" class="btn min-h-10 shrink-0 {{ $tab === $key ? 'btn-ink' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($tab === 'templates')
        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-3 lg:col-span-2">
                @foreach ($templates as $template)
                    <details class="card" @if ($errors->any() && old('_template') == $template->id) open @endif>
                        <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3">
                            <span class="font-semibold">{{ $template->name }}</span>
                            <span class="flex items-center gap-2 text-xs text-ink-3">
                                {{ \App\Services\Crm\Vocab::channels()[$template->channel] ?? $template->channel }}
                                @unless ($template->active)<span class="stamp border-ink-3 text-ink-3">{{ __('Off') }}</span>@endunless
                            </span>
                        </summary>
                        <form method="POST" action="{{ route('crm.templates.update', $template) }}" class="space-y-3 border-t border-rule p-4" x-data="{ channel: @js($template->channel) }">
                            @csrf @method('PATCH')
                            <input type="hidden" name="_template" value="{{ $template->id }}">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div><label class="label" for="t-name-{{ $template->id }}">{{ __('Name') }}</label><input id="t-name-{{ $template->id }}" name="name" value="{{ $template->name }}" class="input" maxlength="120" required></div>
                                <div><label class="label" for="t-ch-{{ $template->id }}">{{ __('Opens in') }}</label>
                                    <select id="t-ch-{{ $template->id }}" name="channel" x-model="channel" class="input">@foreach (\App\Services\Crm\Vocab::channels() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                            </div>
                            <div x-show="channel === 'email'"><label class="label" for="t-sub-{{ $template->id }}">{{ __('Subject') }}</label><input id="t-sub-{{ $template->id }}" name="subject" value="{{ $template->subject }}" class="input" maxlength="200"></div>
                            <div><label class="label" for="t-body-{{ $template->id }}">{{ __('Message') }}</label><textarea id="t-body-{{ $template->id }}" name="body" rows="6" class="input" maxlength="3000" required>{{ $template->body }}</textarea></div>
                            <label class="flex items-center gap-2 text-sm font-semibold text-ink-2"><input type="checkbox" name="active" value="1" class="size-4" @checked($template->active)> {{ __('Offer this template') }}</label>
                            <div class="flex items-center justify-between gap-2">
                                <button class="btn btn-primary min-h-10">{{ __('Save') }}</button>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('crm.templates.destroy', $template) }}" class="border-t border-rule px-4 py-2 text-right" onsubmit="return confirm('{{ __('Delete this template?') }}')">@csrf @method('DELETE')<button class="link text-xs text-laterite">{{ __('Delete') }}</button></form>
                    </details>
                @endforeach
            </div>

            <div class="space-y-5 self-start">
                <form method="POST" action="{{ route('crm.templates.store') }}" class="card" x-data="{ channel: 'whatsapp' }">
                    @csrf
                    <div class="card-head"><h2 class="card-title">{{ __('New template') }}</h2></div>
                    <div class="space-y-3 p-4">
                        <x-field :label="__('Name')" name="name"><input id="name" name="name" value="{{ old('name') }}" class="input" maxlength="120" required></x-field>
                        <x-field :label="__('Opens in')" name="channel">
                            <select id="channel" name="channel" x-model="channel" class="input">@foreach (\App\Services\Crm\Vocab::channels() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                        </x-field>
                        <div x-show="channel === 'email'"><x-field :label="__('Subject')" name="subject"><input id="subject" name="subject" value="{{ old('subject') }}" class="input" maxlength="200"></x-field></div>
                        <x-field :label="__('Message')" name="body"><textarea id="body" name="body" rows="6" class="input" maxlength="3000" required>{{ old('body') }}</textarea></x-field>
                        <button class="btn btn-primary w-full">{{ __('Add template') }}</button>
                    </div>
                </form>
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Words you can use') }}</h2></div>
                    <div class="space-y-2 p-4 text-sm">
                        <p class="text-ink-3">{{ __('Write a word in curly brackets and it is replaced with the details of the lead, tender or company.') }}</p>
                        <p class="flex flex-wrap gap-1.5">@foreach ($placeholders as $word)<code class="num rounded-[2px] bg-paper-2 px-1.5 py-0.5 text-xs">{{ '{'.$word.'}' }}</code>@endforeach</p>
                    </div>
                </section>
            </div>
        </div>
    @elseif ($tab === 'reasons')
        <div class="grid gap-5 lg:grid-cols-3">
            @foreach (\App\Services\Crm\Vocab::reasonKinds() as $kind => $title)
                <section class="card self-start">
                    <div class="card-head"><h2 class="card-title">{{ $title }}</h2></div>
                    @foreach ($reasons->get($kind, collect()) as $reason)
                        <form method="POST" action="{{ route('crm.reasons.update', $reason) }}" class="flex items-center gap-2 border-b border-rule px-4 py-2">
                            @csrf @method('PATCH')
                            <input name="label" value="{{ $reason->label }}" class="input min-h-9 flex-1" maxlength="120" required aria-label="{{ __('Reason') }}">
                            <label class="flex shrink-0 items-center gap-1 text-xs text-ink-2"><input type="checkbox" name="active" value="1" class="size-4" @checked($reason->active)> {{ __('On') }}</label>
                            <button class="btn btn-ghost min-h-9 px-2.5">{{ __('Save') }}</button>
                        </form>
                    @endforeach
                    <form method="POST" action="{{ route('crm.reasons.store') }}" class="flex gap-2 p-4">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <input name="label" class="input min-h-9 flex-1" maxlength="120" required placeholder="{{ __('Add a reason') }}" aria-label="{{ __('Add a reason') }}">
                        <button class="btn btn-primary min-h-9">{{ __('Add') }}</button>
                    </form>
                </section>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-ink-3">{{ __('Reasons are switched off, not deleted, so tenders closed earlier keep theirs.') }}</p>
    @else
        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                @foreach (\App\Services\Crm\Vocab::entities() as $entity => $title)
                    <section class="card">
                        <div class="card-head"><h2 class="card-title">{{ $title }}</h2></div>
                        @forelse ($fields->get($entity, collect()) as $field)
                            <form method="POST" action="{{ route('crm.fields.update', $field) }}" class="grid gap-2 border-b border-rule px-4 py-3 sm:grid-cols-6">
                                @csrf @method('PATCH')
                                <input name="label" value="{{ $field->label }}" class="input sm:col-span-2" maxlength="80" required aria-label="{{ __('Label') }}">
                                <span class="flex items-center text-sm text-ink-3">{{ \App\Services\Crm\Vocab::fieldTypes()[$field->type] ?? $field->type }}</span>
                                @if ($field->type === 'select')
                                    <input name="options" value="{{ implode(', ', $field->options ?? []) }}" class="input sm:col-span-2" maxlength="1000" aria-label="{{ __('Choices') }}">
                                @else
                                    <span class="sm:col-span-2"></span>
                                @endif
                                <div class="flex items-center gap-3 sm:col-span-6">
                                    <label class="flex items-center gap-1 text-xs text-ink-2"><input type="checkbox" name="required" value="1" class="size-4" @checked($field->required)> {{ __('Required') }}</label>
                                    <label class="flex items-center gap-1 text-xs text-ink-2"><input type="checkbox" name="active" value="1" class="size-4" @checked($field->active)> {{ __('Shown') }}</label>
                                    <button class="btn btn-ghost ml-auto min-h-9">{{ __('Save') }}</button>
                                </div>
                            </form>
                        @empty
                            <p class="px-4 py-4 text-sm text-ink-3">{{ __('No custom fields yet.') }}</p>
                        @endforelse
                    </section>
                @endforeach
            </div>

            <form method="POST" action="{{ route('crm.fields.store') }}" class="card self-start" x-data="{ type: 'text' }">
                @csrf
                <div class="card-head"><h2 class="card-title">{{ __('New field') }}</h2></div>
                <div class="space-y-3 p-4">
                    <x-field :label="__('Applies to')" name="entity">
                        <select id="entity" name="entity" class="input">@foreach (\App\Services\Crm\Vocab::entities() as $key => $label)<option value="{{ $key }}" @selected(old('entity') === $key)>{{ $label }}</option>@endforeach</select>
                    </x-field>
                    <x-field :label="__('Label')" name="label"><input id="label" name="label" value="{{ old('label') }}" class="input" maxlength="80" required></x-field>
                    <x-field :label="__('Type')" name="type">
                        <select id="type" name="type" x-model="type" class="input">@foreach (\App\Services\Crm\Vocab::fieldTypes() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                    </x-field>
                    <div x-show="type === 'select'" x-cloak><x-field :label="__('Choices')" name="options" :hint="__('Separate with commas.')"><input id="options" name="options" value="{{ old('options') }}" class="input" maxlength="1000"></x-field></div>
                    <input type="hidden" name="required" value="0">
                    <label class="flex items-center gap-2 text-sm font-semibold text-ink-2"><input type="checkbox" name="required" value="1" class="size-4"> {{ __('Required') }}</label>
                    <button class="btn btn-primary w-full">{{ __('Add field') }}</button>
                </div>
            </form>
        </div>
        <p class="mt-3 text-xs text-ink-3">{{ __('Fields are switched off, not deleted, so saved values are not lost.') }}</p>
    @endif
@endsection
