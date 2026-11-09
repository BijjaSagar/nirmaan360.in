@extends('layouts.app')
@section('title', __('BOQ setup'))
@section('content')
    @php
        $modes = [
            'detailed' => [__('Detailed, line by line'), __('Every item of work on its own line, with its own quantity and rate. Best when you have drawings or an estimate.')],
            'thumb_rule' => [__('Thumb rule, overall lines'), __('A few lines for whole heads of work, such as structure or finishing, each priced per square foot of built-up area. Quick to start, and you can add detail later.')],
        ];
        $mode = $project->boq_mode ?? 'detailed';
    @endphp

    <x-page-header :title="__('BOQ setup')" :subtitle="$project->name">
        <a href="{{ route('boq.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('Back to BOQ & ledger') }}</a>
    </x-page-header>

    <div class="space-y-5">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('How detailed is this BOQ?') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                @foreach ($modes as $key => [$label, $note])
                    @can('boq.manage')
                        <form method="POST" action="{{ route('project-setup.boq-setup.mode', $project) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="boq_mode" value="{{ $key }}">
                            <button class="block min-h-12 w-full rounded-[3px] border p-3 text-left {{ $mode === $key ? 'border-neel bg-neel/5' : 'border-rule bg-sheet hover:border-ink-3' }}" @if ($mode === $key) aria-current="true" @endif>
                                <span class="flex items-center justify-between gap-2 font-semibold">{{ $label }} @if ($mode === $key)<span class="stamp border-neel text-neel">{{ __('In use') }}</span>@endif</span>
                                <span class="mt-1 block text-sm text-ink-3">{{ $note }}</span>
                            </button>
                        </form>
                    @else
                        <div class="rounded-[3px] border p-3 {{ $mode === $key ? 'border-neel bg-neel/5' : 'border-rule' }}">
                            <span class="flex items-center justify-between gap-2 font-semibold">{{ $label }} @if ($mode === $key)<span class="stamp border-neel text-neel">{{ __('In use') }}</span>@endif</span>
                            <span class="mt-1 block text-sm text-ink-3">{{ $note }}</span>
                        </div>
                    @endcan
                @endforeach
            </div>
            <p class="px-4 pb-4 text-sm text-ink-3">{{ __('Changing this does not touch the lines you already have. It only changes which starter list is offered.') }}</p>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Starter BOQ') }}</h2></div>
            <div class="p-4">
                <p class="text-sm text-ink-3">{{ __('The usual items in the order work happens. Only the names, units and kinds of work are filled in. The quantity and rate of every item are yours to enter, because they are specific to this project.') }}</p>
                <ol class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    @foreach ($template as $index => [$description, $unit])
                        <li class="flex gap-2"><span class="num w-6 shrink-0 text-ink-3">{{ $index + 1 }}</span><span>{{ __($description) }} <span class="text-ink-3">· {{ $unit }}</span></span></li>
                    @endforeach
                </ol>
                @if ($lineCount === 0)
                    <div class="mt-4">@include('modules.project_setup.boq._starter-button', ['project' => $project])</div>
                @else
                    <p class="mt-4 rounded-[3px] border border-rule bg-paper/60 px-3 py-2 text-sm text-ink-2">{{ __('This BOQ already has :count items, so the starter list is not added again.', ['count' => $lineCount]) }}</p>
                @endif
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Concrete: by cubic metre or by ingredients') }}</h2></div>
            <div class="p-4">
                <p class="text-sm text-ink-3">{{ __('Ready-mix (RMC) is entered by the cubic metre, as on the BOQ line. Concrete mixed on site is entered by what goes into it: cement in bags, coarse aggregate and sand in brass. The ingredients feed the material check against stock. Your rate for the item stays as you entered it.') }}</p>

                @if ($concrete->isEmpty())
                    <p class="mt-3 rounded-[3px] border border-rule bg-paper/60 px-3 py-2 text-sm text-ink-2">{{ __('No concrete items yet. Give a BOQ item the category PCC, Foundation concrete or RCC and it appears here.') }}</p>
                @else
                    @if ($materials->isEmpty())
                        <p class="mt-3 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm text-ink-2">
                            {{ __('To enter ingredients, first add cement, aggregate and sand to the materials list.') }}
                            @if (auth()->user()->organization->allowsFeature('procurement') && auth()->user()->can('procurement.view'))
                                <a href="{{ route('procurement.materials.index') }}" class="link">{{ __('Open materials') }}</a>
                            @endif
                        </p>
                    @endif

                    <div class="mt-4 space-y-3">
                        @foreach ($concrete as $row)
                            @php $item = $row['item']; @endphp
                            <details class="rounded-[4px] border border-rule" @if ((string) old('_item') === (string) $item->id) open @endif>
                                <summary class="flex min-h-12 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5">
                                    {{-- On a phone the description gets a line of its own, between the code and the quantity. --}}
                                    <span class="num order-1 text-xs text-ink-3 sm:order-none">{{ $item->code }}</span>
                                    <span class="order-3 min-w-0 basis-full font-medium sm:order-none sm:flex-1 sm:basis-0">{{ $item->description }}</span>
                                    <span class="num order-4 text-sm text-ink-2 sm:order-none">{{ fmt_qty($item->qty) }} {{ $item->unit }}</span>
                                    <span class="stamp order-2 ml-auto sm:order-none sm:ml-0 {{ $row['mix']['mode'] === 'ingredients' ? 'border-neel text-neel' : 'border-ink-3 text-ink-3' }}">{{ $row['mix']['mode'] === 'ingredients' ? __('Mixed on site') : __('Ready-mix (RMC)') }}</span>
                                </summary>
                                <div class="border-t border-rule p-3">
                                    @can('boq.manage')
                                        @include('modules.project_setup._mix-form', ['row' => $row, 'item' => $item])
                                    @else
                                        @include('modules.project_setup._mix-summary', ['row' => $row, 'item' => $item])
                                    @endcan
                                </div>
                            </details>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
