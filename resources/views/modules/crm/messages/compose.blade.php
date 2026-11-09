@extends('layouts.app')
@section('title', $template->name)
@section('content')
    @php
        $isWhatsapp = $template->channel === 'whatsapp';
        $recipient = $isWhatsapp ? $message['phone'] : $message['email'];
        $ready = $isWhatsapp ? $message['whatsapp_url'] !== null : $message['mailto_url'] !== null;
    @endphp
    <x-page-header :title="$template->name" :subtitle="$subjectTitle">
        <a href="{{ $backUrl }}" class="btn btn-ghost">{{ __('Back') }}</a>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3"
         x-data="{
            phone: @js(\App\Services\Crm\MessageService::normalisePhone($message['phone'])),
            email: @js($ready && ! $isWhatsapp ? $message['email'] : null),
            subject: @js($message['subject']),
            body: @js($message['body']),
            get waUrl() { return this.phone ? 'https://wa.me/' + this.phone + '?text=' + encodeURIComponent(this.body) : null },
            get mailUrl() { return this.email ? 'mailto:' + encodeURIComponent(this.email) + '?subject=' + encodeURIComponent(this.subject) + '&body=' + encodeURIComponent(this.body) : null },
            get url() { return {{ $isWhatsapp ? 'this.waUrl' : 'this.mailUrl' }} },
         }">
        <section class="card lg:col-span-2">
            <div class="card-head">
                <h2 class="card-title">{{ $isWhatsapp ? __('WhatsApp message') : __('Email') }}</h2>
                <span class="text-xs text-ink-3">{{ $recipient ?: __('No number or address on file') }}</span>
            </div>
            <div class="space-y-3 p-4">
                @if (! $ready)
                    <p class="rounded-[3px] border border-haldi bg-haldi/10 p-3 text-sm">
                        {{ $isWhatsapp ? __('There is no usable mobile number for this record, so the message cannot open in WhatsApp. Add a number first, or copy the text below.') : __('There is no valid email address for this record, so the message cannot open in your mail app. Add one first, or copy the text below.') }}
                    </p>
                @endif
                @if (! empty($message['empty']))
                    <p class="rounded-[3px] border border-rule bg-paper-2/50 p-3 text-sm">{{ __('This record has nothing for: :fields. Fill the gaps in the message before sending.', ['fields' => implode(', ', array_map(fn ($name) => '{'.$name.'}', $message['empty']))]) }}</p>
                @endif
                @unless ($isWhatsapp)
                    <x-field :label="__('Subject')" name="subject"><input id="subject" x-model="subject" class="input" maxlength="200"></x-field>
                @endunless
                <x-field :label="__('Message')" name="body" :hint="__('You can change the words before sending.')"><textarea id="body" x-model="body" rows="9" class="input"></textarea></x-field>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($ready)
                        <a :href="url" target="_blank" rel="noopener noreferrer" class="btn btn-primary min-h-11">{{ $isWhatsapp ? __('Open in WhatsApp') : __('Open in mail app') }}</a>
                    @endif
                    <button type="button" class="btn btn-ghost min-h-11" x-data="{ copied: false }" @click="navigator.clipboard.writeText(body).then(() => { copied = true; setTimeout(() => copied = false, 1500) })" x-text="copied ? @js(__('Copied')) : @js(__('Copy text'))"></button>
                </div>
            </div>
        </section>

        <section class="card self-start">
            <div class="card-head"><h2 class="card-title">{{ __('After you send it') }}</h2></div>
            <form method="POST" action="{{ route('crm.messages.log') }}" class="space-y-2 p-4">
                @csrf
                <input type="hidden" name="subject_type" value="{{ $subjectType }}">
                <input type="hidden" name="subject_id" value="{{ $subject->getKey() }}">
                <input type="hidden" name="channel" value="{{ $template->channel }}">
                <input type="hidden" name="summary" :value="body.slice(0, 280)">
                <p class="text-sm text-ink-3">{{ __('Nothing is sent from this page. Once you have sent it yourself, note it on the timeline.') }}</p>
                @can('crm.manage')<button class="btn btn-ghost w-full">{{ __('I sent it, log it') }}</button>@endcan
            </form>
        </section>
    </div>
@endsection
