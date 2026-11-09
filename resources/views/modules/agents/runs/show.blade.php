@extends('layouts.app')
@section('title', __('Run').' #'.$run->id)
@section('content')
    @inject('approvalService', 'App\Services\ApprovalService')
    <x-page-header :title="__('Run').' #'.$run->id" :subtitle="__($run->agent->name).' · v'.$run->agentVersion->version.($run->project ? ' · '.$run->project->name : '')">
        <x-stamp :status="$run->status" />
        @if (! $run->isFinished() && auth()->user()->can('agents.use'))
            <form method="POST" action="{{ route('agent-runs.cancel', $run) }}">@csrf<button class="btn btn-ghost">{{ __('Cancel run') }}</button></form>
        @endif
    </x-page-header>

    @include('modules.agents._connection')

    @unless ($run->isFinished())
        @include('modules.agents._poll', ['run' => $run])
    @endunless

    {{-- Not x-stat: that truncates, and on a phone these values must stay readable in full. --}}
    <section class="card mb-5 grid grid-cols-2 gap-x-4 gap-y-3 p-4 md:grid-cols-4">
        @foreach ([
            __('Model') => $run->model,
            __('Steps') => $run->steps_count.' / '.$run->max_steps,
            __('Tokens in / out') => number_format($run->input_tokens + $run->cache_read_tokens + $run->cache_write_tokens).' / '.number_format($run->output_tokens),
            __('Credits') => \App\Support\Dec::indian($run->credits_used, 2).' / '.\App\Support\Dec::indian($run->max_credits, 2),
        ] as $label => $value)
            <div class="min-w-0">
                <p class="eyebrow text-[10px] text-ink-3">{{ $label }}</p>
                <p class="num mt-0.5 text-[15px] font-medium break-words">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="min-w-0 space-y-5 lg:col-span-2">
            <section class="card p-4">
                <p class="eyebrow text-ink-3">{{ __('Asked') }}</p>
                <p class="mt-0.5 break-words whitespace-pre-line">{{ $run->input }}</p>
                <p class="eyebrow mt-3 text-ink-3">{{ __('Answer') }}</p>
                @if ($run->answer !== null)
                    <p class="mt-0.5 break-words whitespace-pre-line">{{ $run->answer }}</p>
                @endif
                @include('modules.agents._outcome')
                @include('modules.agents._drafts')
                @include('modules.agents._sources', ['sources' => $run->sources])
            </section>

            @foreach ($run->steps as $step)
                <section class="card">
                    <div class="card-head flex-wrap">
                        <h2 class="card-title">{{ __('Step :number', ['number' => $step->number]) }}@if ($step->purpose === 'title') · {{ __('chat title') }}@endif</h2>
                        <span class="num text-xs text-ink-3">{{ $step->model }} · {{ number_format($step->input_tokens + $step->cache_read_tokens + $step->cache_write_tokens) }} / {{ number_format($step->output_tokens) }} · {{ \App\Support\Dec::indian($step->credits, 2) }} {{ __('credits') }} · {{ number_format($step->latency_ms) }} ms</span>
                    </div>
                    {{-- A step that ended the run holds the final JSON, which is already shown above as the answer. --}}
                    @php $stepNote = $step->purpose !== 'title' && $step->stop_reason !== 'end_turn' ? $step->text : null; @endphp
                    @if ($stepNote)
                        <p class="px-4 pt-3 text-sm break-words whitespace-pre-line text-ink-2">{{ $stepNote }}</p>
                    @endif
                    @forelse ($step->toolCalls as $call)
                        <div class="border-b border-rule px-4 py-3 last:border-b-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="num text-sm font-semibold">{{ $call->tool }}</span>
                                <x-stamp :status="$call->status" />
                                @if ($call->injection_flags)
                                    <span class="text-xs text-laterite">{{ __('Text that looked like an instruction was removed') }}</span>
                                @endif
                            </div>
                            @if ($call->error)
                                <p class="mt-1 text-sm text-laterite">{{ $call->error }}</p>
                            @endif
                            <details class="mt-2">
                                <summary class="cursor-pointer text-xs text-ink-3">{{ __('Input and output') }}</summary>
                                <div class="mt-2 grid gap-2 md:grid-cols-2">
                                    <pre class="overflow-x-auto rounded-[3px] bg-paper-2/60 p-2 text-[11px]">{{ json_encode($call->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    <pre class="overflow-x-auto rounded-[3px] bg-paper-2/60 p-2 text-[11px]">{{ json_encode($call->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            </details>
                        </div>
                    @empty
                        @if ($step->purpose !== 'title')
                            <p class="px-4 py-3 text-xs text-ink-3">{{ __('No tools used in this step.') }}</p>
                        @endif
                    @endforelse
                </section>
            @endforeach
        </div>

        <div class="space-y-5">
            <section class="card p-4 text-sm">
                <dl class="space-y-1.5">
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Started by') }}</dt><dd class="text-right">{{ $run->actor?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Level') }}</dt><dd class="text-right">{{ __(\App\Services\Agents\Autonomy::LEVELS[$run->autonomy]) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Asked on') }}</dt><dd class="num">{{ $run->created_at->format('d M Y H:i') }}</dd></div>
                    @if ($run->finished_at)
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Finished') }}</dt><dd class="num">{{ $run->finished_at->format('d M Y H:i') }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Time used') }}</dt><dd class="num">{{ round($run->active_ms / 1000, 1) }} / {{ $run->max_seconds }} s</dd></div>
                </dl>
                @if ($run->agent_conversation_id && $run->user_id === auth()->id())
                    <a href="{{ route('agents.chats.show', $run->agent_conversation_id) }}" class="link mt-3 inline-block text-[13px]">{{ __('Back to chat') }}</a>
                @endif
            </section>

            @foreach ($run->actionRequests as $action)
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Needs a person') }}</h2><x-stamp :status="$action->status" /></div>
                    <p class="px-4 pt-3 text-sm">{{ $action->summary }}</p>
                    <x-approval-trail :approvals="$action->approvals" />
                </section>
            @endforeach
        </div>
    </div>
@endsection
