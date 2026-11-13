{{-- The lines of a quotation. $tick: on the review screen each line has a tick that stands for its quantity, rate and amount. $ticked: the ticks to show as set. --}}
<div class="overflow-x-auto">
    <table class="ledger min-w-[680px]">
        <thead>
            <tr>
                <th>{{ __('Item') }}</th><th class="w-2/5">{{ __('Description') }}</th><th>{{ __('Unit') }}</th>
                <th class="r">{{ __('Quantity') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Amount') }}</th>
                @if ($tick)<th class="r">{{ __('Checked') }}</th>@endif
            </tr>
        </thead>
        <tbody>
        @foreach ($items as $line)
            <tr>
                <td class="num text-xs">{{ $line->code }}</td>
                <td class="whitespace-pre-line">{{ $line->description }}</td>
                <td>{{ $line->unit }}</td>
                <td class="r">{{ fmt_qty($line->qty) }}</td>
                <td class="r">{{ inr($line->rate) }}</td>
                <td class="r">{{ inr($line->amount) }}</td>
                @if ($tick)
                    <td class="r"><input type="checkbox" form="issue-form" name="number_ticks[]" value="line_{{ $loop->iteration }}" aria-label="{{ __('Line :n', ['n' => $loop->iteration]) }}" @checked(in_array('line_'.$loop->iteration, $ticked ?? [], true))></td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
