{{--
    The work rows of an update, added up per BOQ line over the week's approved daily reports.
    $work: the rows from OwnerUpdateDocument::workLines(). $tick: on the review screen each row has a tick
    that stands for what was done, its quantity and its unit. $ticked: the ticks to show as set.
--}}
<div class="overflow-x-auto">
    <table class="ledger min-w-[480px]">
        <thead>
            <tr>
                <th class="w-3/5">{{ __('Work done') }}</th><th class="r">{{ __('Quantity') }}</th><th>{{ __('Unit') }}</th>
                @if ($tick)<th class="r">{{ __('Checked') }}</th>@endif
            </tr>
        </thead>
        <tbody>
        @foreach ($work as $row)
            <tr>
                <td class="whitespace-pre-line">{{ $row['name'] }}</td>
                <td class="r">{{ $row['qty'] ?? '—' }}</td>
                <td>{{ $row['unit'] ?? '—' }}</td>
                @if ($tick)
                    <td class="r"><input type="checkbox" form="approve-form" name="number_ticks[]" value="work_{{ $row['n'] }}" aria-label="{{ __('Work row :n', ['n' => $row['n']]) }}" @checked(in_array('work_'.$row['n'], $ticked ?? [], true))></td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
