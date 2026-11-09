@if ($bill->acceptsNotes())
    <section class="card">
        <div class="card-head">
            <h2 class="card-title">{{ __('Credit and debit notes') }}</h2>
            @can('bill_note.manage')<a href="{{ route('bill-notes.create', $bill) }}" class="btn btn-ghost py-1 text-xs">{{ __('Raise note') }}</a>@endcan
        </div>
        @can('bill_note.view')
            @forelse ($bill->notes as $note)
                <a href="{{ route('bill-notes.show', $note) }}" class="flex items-center justify-between gap-2 border-b border-rule px-4 py-2 text-sm last:border-b-0 hover:bg-paper/70">
                    <span class="num text-xs">{{ $note->number }}</span>
                    <span class="num ml-auto whitespace-nowrap {{ $note->lowersPosition() ? 'text-laterite' : '' }}">{{ $note->lowersPosition() ? '−' : '+' }}{{ inr($note->total_amount) }}</span>
                    <x-stamp :status="$note->status" />
                </a>
            @empty
                <p class="px-4 py-3 text-sm text-ink-3">{{ __('No notes against this bill.') }}</p>
            @endforelse
        @endcan
        <div class="flex items-baseline justify-between border-t border-rule bg-paper-2/60 px-4 py-2 text-sm">
            <span class="font-semibold">{{ __('Net after approved notes') }}</span>
            <span class="num font-medium whitespace-nowrap">{{ inr($bill->adjustedNet()) }}</span>
        </div>
    </section>
@endif
