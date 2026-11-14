{{-- The company's saved BOQ file layouts, on the BOQ in Excel page. Needs $layouts. --}}
@if ($layouts->isNotEmpty())
    <section class="card mt-5">
        <div class="card-head">
            <h2 class="card-title">{{ __('Saved file layouts') }}</h2>
            <span class="num text-sm text-ink-3">{{ $layouts->count() }}</span>
        </div>
        <p class="px-4 pt-3 text-sm text-ink-3">{{ __('Each time a file is imported after checking its columns, its layout is remembered for the company. The next file laid out the same way has its columns set for you; you still check and confirm it. Remove a layout to have such files read afresh.') }}</p>
        <div class="overflow-x-auto p-4">
            <table class="ledger">
                <thead>
                <tr>
                    <th>{{ __('Headings') }}</th>
                    <th>{{ __('First saved from') }}</th>
                    <th class="r">{{ __('Used') }}</th>
                    <th>{{ __('Last used') }}</th>
                    <th class="w-24"></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($layouts as $layout)
                    <tr>
                        <td>{{ \Illuminate\Support\Str::limit(implode(' | ', array_filter((array) $layout->headings)), 120) }}</td>
                        <td class="text-ink-3">{{ $layout->file_name ?? '—' }}@if ($layout->sheet_name) · {{ $layout->sheet_name }}@endif</td>
                        <td class="num r">{{ $layout->uses }}</td>
                        <td class="text-ink-3">{{ $layout->last_used_at?->timezone('Asia/Kolkata')->format('d M Y') ?? '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('boq.layouts.destroy', $layout) }}" onsubmit="return confirm(@js(__('Remove this saved layout?')))">
                                @csrf
                                @method('DELETE')
                                <button class="link text-sm text-laterite">{{ __('Remove') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
