{{-- Days left and work left, from the contract row's deadline fields. --}}
@php
    use App\Services\ContractorControl\Figures;
    $when = $row['deadline'] ? \Illuminate\Support\Carbon::parse($row['deadline'])->format('d M Y') : null;
    $why = $row['deadline_label'] ? ' · '.$row['deadline_label'] : '';
@endphp
<p class="text-xs {{ in_array($row['time_state'], ['overdue', 'due_today'], true) ? 'font-medium text-laterite' : 'text-ink-3' }}">
    @switch($row['time_state'])
        @case('closed')
            {{ __('Contract closed') }}
            @break
        @case('done')
            {{ __('Work complete') }}
            @break
        @case('no_date')
            {{ __(':pct% of work left · no deadline set', ['pct' => Figures::pct($row['balance_work_pct'])]) }}
            @break
        @case('overdue')
            {{ trans_choice(':count day past the deadline|:count days past the deadline', abs($row['days_left']), ['count' => abs($row['days_left'])]) }} · {{ __(':pct% of work left', ['pct' => Figures::pct($row['balance_work_pct'])]) }} · {{ $when }}{{ $why }}
            @break
        @case('due_today')
            {{ __('Due today') }} · {{ __(':pct% of work left', ['pct' => Figures::pct($row['balance_work_pct'])]) }}{{ $why }}
            @break
        @default
            {{ trans_choice(':count day left|:count days left', $row['days_left'], ['count' => $row['days_left']]) }} · {{ __(':pct% of work left', ['pct' => Figures::pct($row['balance_work_pct'])]) }} · {{ __('finish by :date', ['date' => $when]) }}{{ $why }}
    @endswitch
</p>
