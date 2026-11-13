@php
    // The ledger and the budget show the project's overall spend, so only money.overview gets those two tabs.
    $overview = auth()->user()->can('money.overview');
    $tabs = [
        ...($overview ? [['finance.project.ledger', __('Ledger'), 'finance.project.ledger']] : []),
        ['finance.project.vendor-bills.index', __('Vendor bills'), 'finance.project.vendor-bills.*'],
        ['finance.project.payments', __('Payments'), 'finance.project.payments*'],
        ['finance.project.receipts', __('Receipts'), 'finance.project.receipts*'],
        ['finance.project.invoices', __('Tax invoices'), 'finance.project.invoices*'],
        ...($overview ? [['finance.project.budget', __('Budget'), 'finance.project.budget*']] : []),
    ];
@endphp
<nav class="mb-5 flex gap-1 overflow-x-auto border-b border-rule" aria-label="{{ __('Finance sections') }}">
    @foreach ($tabs as [$route, $label, $active])
        <a href="{{ route($route, $project) }}" class="border-b-2 px-3 py-2 text-sm font-semibold whitespace-nowrap {{ request()->routeIs($active) ? 'border-neel text-neel' : 'border-transparent text-ink-3 hover:text-ink' }}">{{ $label }}</a>
    @endforeach
</nav>
