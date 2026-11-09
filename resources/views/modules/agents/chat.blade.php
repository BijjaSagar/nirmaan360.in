@extends('layouts.app')
@section('title', __($agent->name))
@section('content')
    @inject('agentGateway', 'App\Services\Agents\ModelGateway')
    <x-page-header :title="$project && $agent->key === 'project_brain' ? __('Ask my project') : __($agent->name)" :subtitle="$project?->name ?? __($agent->description)">
        @if ($conversation)
            <a href="{{ $project && $agent->key === 'project_brain' ? route('agents.ask', $project) : route('agents.chat', $agent->key) }}" class="btn btn-ghost">{{ __('New chat') }}</a>
        @endif
    </x-page-header>

    @include('modules.agents._connection')

    <div class="grid gap-5 lg:grid-cols-[1fr_16rem]">
        <div class="min-w-0 space-y-3">
            @forelse ($runs as $run)
                <div class="ml-auto w-fit max-w-[90%] rounded-[4px] bg-neel-deep px-3 py-2 text-sm whitespace-pre-line text-paper">{{ $run->input }}</div>

                <section class="card max-w-full p-3">
                    @if ($run->answer !== null)
                        <p class="text-sm break-words whitespace-pre-line">{{ $run->answer }}</p>
                    @endif

                    @if (! empty($run->output['decisions']))
                        <div class="mt-3 border-t border-rule pt-2">
                            <p class="eyebrow text-[10px] text-ink-3">{{ __('Decisions for you') }}</p>
                            <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm">
                                @foreach (array_slice($run->output['decisions'], 0, 3) as $decision)
                                    <li><span class="font-semibold">{{ $decision['title'] }}</span> <span class="text-ink-3">{{ $decision['why'] }}</span></li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    @include('modules.agents._outcome')
                    @include('modules.agents._drafts')
                    @include('modules.agents._sources', ['sources' => $run->sources])

                    <p class="mt-2 text-right text-[11px] text-ink-3"><a href="{{ route('agent-runs.show', $run) }}" class="link">{{ __('See how this was worked out') }}</a></p>
                </section>
            @empty
                <x-empty :title="__('Start by typing below')" :note="$agent->key === 'site_reporter' ? __('For example: आज १२ गवंडी, ३० कामगार, स्लॅब कास्टिंग २२ घनमीटर') : ($agent->key === 'project_brain' ? __('For example: how much slab concrete has been measured this month?') : null)" />
            @endforelse

            @if ($pending)
                @include('modules.agents._poll', ['run' => $pending])
            @endif

            <form method="POST" action="{{ route('agents.chat.send', $agent->key) }}" class="card p-3">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                @if ($project)<input type="hidden" name="project_id" value="{{ $project->id }}">@endif
                @if ($conversation)<input type="hidden" name="conversation_id" value="{{ $conversation->id }}">@endif
                <label class="sr-only" for="message">{{ __('Your message') }}</label>
                <textarea id="message" name="message" rows="3" class="input" placeholder="{{ __('Type here, in English, Marathi or Hindi') }}" required maxlength="4000">{{ old('message') }}</textarea>
                @error('message')<p class="error">{{ $message }}</p>@enderror
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <button class="btn btn-primary" @disabled(! $agentGateway->isConnected())>{{ __('Send') }}</button>
                    @if ($agent->key === 'owners_copilot' && ! $conversation)
                        <button class="btn btn-ghost" name="message" value="{{ __('Give me today\'s brief.') }}" formnovalidate @disabled(! $agentGateway->isConnected())>{{ __('Today\'s brief') }}</button>
                    @endif
                </div>
            </form>
        </div>

        @if ($recent->isNotEmpty())
            <aside class="card self-start">
                <div class="card-head"><h2 class="card-title">{{ __('Recent chats') }}</h2></div>
                @foreach ($recent as $item)
                    <a href="{{ route('agents.chats.show', $item) }}" class="block truncate border-b border-rule px-4 py-2 text-sm last:border-b-0 {{ $conversation?->id === $item->id ? 'font-semibold' : '' }}">{{ $item->displayTitle() }}</a>
                @endforeach
            </aside>
        @endif
    </div>
@endsection
