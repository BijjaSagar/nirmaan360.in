{{-- The working of a hire bill. Expects $calc (HireBillService::calculate) and $unitLabel. --}}
<table class="ledger">
    <tbody>
    <tr><td>{{ __('Charged') }}</td><td class="r">{{ __(config('modules.assets.hire_bases')[$calc['basis']] ?? $calc['basis']) }} @ {{ inr($calc['rate']) }}</td></tr>
    <tr><td>{{ __('Quantity from the machine log') }}</td><td class="r">{{ fmt_qty($calc['actual_quantity']) }} {{ $unitLabel }}</td></tr>
    @if (! empty($calc['minimum_quantity']))
        <tr><td>{{ __('Minimum for the days it was available') }}</td><td class="r">{{ fmt_qty($calc['minimum_quantity']) }} {{ $unitLabel }}</td></tr>
    @endif
    <tr><td class="font-semibold">{{ __('Billed quantity') }}</td><td class="r font-semibold">{{ fmt_qty($calc['quantity']) }} {{ $unitLabel }}</td></tr>
    <tr><td class="font-semibold">{{ __('Hire charges') }}</td><td class="r font-semibold">{{ inr($calc['gross_amount']) }}</td></tr>
    @if ($calc['hire_type'] === 'wet' || $calc['fuel_recovery'] !== '0.00')
        <tr><td>{{ __('Less: fuel we issued') }} <span class="text-xs text-ink-3">({{ fmt_qty($calc['fuel_litres']) }} L)</span></td><td class="r">− {{ inr($calc['fuel_recovery']) }}</td></tr>
    @endif
    @if ($calc['other_deduction'] !== '0.00')
        <tr><td>{{ __('Less: other deduction') }}@if (! empty($note)) <span class="text-xs text-ink-3">({{ $note }})</span>@endif</td><td class="r">− {{ inr($calc['other_deduction']) }}</td></tr>
    @endif
    @if ($calc['gst_pct'] !== '0.00')
        <tr><td>{{ __('Plus: GST') }} <span class="text-xs text-ink-3">({{ $calc['gst_pct'] }}%)</span></td><td class="r">+ {{ inr($calc['gst_amount']) }}</td></tr>
    @endif
    @if ($calc['tds_pct'] !== '0.00')
        <tr><td>{{ __('Less: TDS') }} <span class="text-xs text-ink-3">({{ $calc['tds_pct'] }}%)</span></td><td class="r">− {{ inr($calc['tds_amount']) }}</td></tr>
    @endif
    <tr class="border-t-2 border-ink"><td class="font-semibold">{{ __('Net payable') }}</td><td class="r text-base font-semibold">{{ inr($calc['net_payable']) }}</td></tr>
    </tbody>
</table>
