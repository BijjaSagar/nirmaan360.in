@extends('layouts.app')
@section('title', __('Productivity'))
@section('content')
    @php $plain = \App\Support\Figure::trim(...); @endphp

    <x-page-header :title="__('Productivity')" :subtitle="$project->name">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="input"></div>
            <div><label class="label" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="input"></div>
            <input type="hidden" name="date" value="{{ $date }}">
            <button class="btn btn-ink">{{ __('Show') }}</button>
        </form>
    </x-page-header>

    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <x-stat :label="__('Man-days in attendance')" :value="$plain($report['attendance_days'])" />
        <x-stat :label="__('Allocated to activities')" :value="$plain($report['allocated_days'])" />
        <x-stat :label="__('Not yet allocated')" :value="$plain($report['unallocated_days'])" :note="__('Allocate them below to include them in productivity.')" :accent="(float) $report['unallocated_days'] > 0" />
    </div>

    <div class="card mb-6 overflow-x-auto">
        <div class="card-head">
            <span class="card-title">{{ __('Work done per man-day') }}</span>
            <span class="text-xs text-ink-3">{{ __('Work done is taken from submitted and approved daily reports.') }}</span>
        </div>
        @if ($report['rows']->isEmpty())
            <x-empty :title="__('Nothing to show for this period')" :note="__('Productivity needs work recorded in daily reports and labour allocated to the same activities.')" />
        @else
            <table class="ledger min-w-[720px]">
                <thead><tr><th>{{ __('Activity') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Work done') }}</th><th class="r">{{ __('Man-days') }}</th><th class="r">{{ __('Per man-day') }}</th></tr></thead>
                <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        <td><span class="num text-xs text-ink-3">{{ $row['item']->code }}</span> {{ $row['item']->description }}</td>
                        <td>{{ $row['item']->unit }}</td>
                        <td class="r">{{ fmt_qty($row['work_done']) }}</td>
                        <td class="r">{{ $row['man_days'] === '0.000' ? '—' : fmt_qty($row['man_days']) }}</td>
                        <td class="r font-semibold">
                            @if ($row['productivity'] !== null){{ fmt_qty($row['productivity']) }} {{ $row['item']->unit }}@else<span class="font-normal text-ink-3">{{ __('No labour allocated') }}</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <span class="card-title">{{ __('Allocate labour') }}</span>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                <input type="date" name="date" value="{{ $date }}" max="{{ now()->format('Y-m-d') }}" class="input" onchange="this.form.submit()" aria-label="{{ __('Date') }}">
            </form>
        </div>

        @if ($roster->isEmpty())
            <x-empty :title="__('Nobody was marked present on this date')" :note="__('Mark attendance first, then say which activity each worker spent the day on.')" />
        @else
            <form method="POST" action="{{ route('workforce.productivity.allocate', $project) }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[720px]">
                        <thead><tr><th>{{ __('Worker') }}</th><th class="r">{{ __('Day') }}</th><th class="r">{{ __('Left') }}</th><th>{{ __('Activity') }}</th><th class="r">{{ __('Man-days') }}</th></tr></thead>
                        <tbody>
                        @foreach ($roster as $row)
                            @php $id = $row['worker']->id; @endphp
                            <tr>
                                <td class="font-semibold">{{ $row['worker']->name }}<span class="block text-xs font-normal text-ink-3">{{ __($row['worker']->trade) }}</span></td>
                                <td class="r">{{ $plain($row['payable']) }}</td>
                                <td class="r">{{ $plain($row['free']) }}</td>
                                <td>
                                    <select name="allocations[{{ $id }}][boq_item_id]" class="input" aria-label="{{ __('Activity') }}" @disabled((float) $row['free'] <= 0)>
                                        <option value="">—</option>
                                        @foreach ($items as $item)<option value="{{ $item->id }}" @selected((int) old("allocations.$id.boq_item_id") === $item->id)>{{ $item->code }} · {{ \Illuminate\Support\Str::limit($item->description, 60) }}</option>@endforeach
                                    </select>
                                </td>
                                <td class="r"><input name="allocations[{{ $id }}][man_days]" type="number" step="0.25" min="0.25" max="{{ $plain($row['free']) }}" value="{{ old("allocations.$id.man_days") }}" class="input num ml-auto w-24 text-right" aria-label="{{ __('Man-days') }}" @disabled((float) $row['free'] <= 0)></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @can('attendance.manage')
                    <div class="border-t border-rule p-4"><button class="btn btn-primary">{{ __('Save allocations') }}</button></div>
                @endcan
            </form>

            @if ($allocations->isNotEmpty())
                <div class="overflow-x-auto border-t border-rule">
                    <table class="ledger min-w-[640px]">
                        <thead><tr><th>{{ __('Worker') }}</th><th>{{ __('Activity') }}</th><th class="r">{{ __('Man-days') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($allocations as $allocation)
                            <tr>
                                <td class="font-semibold">{{ $allocation->worker?->name }}</td>
                                <td>{{ $allocation->boqItem?->code }} · {{ \Illuminate\Support\Str::limit((string) $allocation->boqItem?->description, 70) }}</td>
                                <td class="r">{{ $plain($allocation->man_days) }}</td>
                                <td class="r">
                                    @can('attendance.manage')
                                        <form method="POST" action="{{ route('workforce.productivity.destroy', $allocation) }}">@csrf @method('DELETE')<button class="link text-xs">{{ __('Remove') }}</button></form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
@endsection
