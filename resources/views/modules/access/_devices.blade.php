{{-- $devices, $revokeRoute (callable: token id to URL), $revokeAllUrl --}}
<div class="card overflow-x-auto">
    <div class="card-head">
        <span class="card-title">{{ __('Signed-in devices') }}</span>
        @if ($devices->isNotEmpty())
            <form method="POST" action="{{ $revokeAllUrl }}">
                @csrf @method('DELETE')
                <button class="btn btn-ghost py-0.5 text-xs">{{ __('Sign out all') }}</button>
            </form>
        @endif
    </div>
    @if ($devices->isEmpty())
        <x-empty :title="__('No signed-in devices')" :note="__('Phones signed in to the field app appear here.')" />
    @else
        <table class="ledger min-w-[560px]">
            <thead><tr><th>{{ __('Device') }}</th><th>{{ __('Signed in') }}</th><th>{{ __('Last used') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($devices as $device)
                <tr>
                    <td class="font-semibold">{{ $device->name }}</td>
                    <td class="num text-xs">{{ $device->created_at?->format('d M Y, H:i') ?? '—' }}</td>
                    <td class="num text-xs">{{ $device->last_used_at?->format('d M Y, H:i') ?? __('Not used yet') }}</td>
                    <td class="r">
                        <form method="POST" action="{{ $revokeRoute($device->id) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger py-0.5 text-xs">{{ __('Sign out') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
