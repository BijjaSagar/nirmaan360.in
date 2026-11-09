<details class="mt-2 text-xs">
    <summary class="link cursor-pointer">{{ __('Before and after photos') }} <span class="num">({{ $issue->photos->count() }})</span></summary>
    <div class="mt-2 grid gap-3 sm:grid-cols-2">
        @foreach (\App\Models\IssuePhoto::KINDS as $kind)
            <div>
                <p class="eyebrow text-[10px] text-ink-3">{{ __(ucfirst($kind)) }}</p>
                <div class="mt-1 flex flex-wrap gap-2">
                    @forelse ($issue->photos->where('kind', $kind) as $photo)
                        <div>
                            <a href="{{ route('issue-photos.show', $photo) }}" target="_blank" rel="noopener">
                                <img src="{{ route('issue-photos.show', $photo) }}" alt="{{ $photo->caption ?: __(ucfirst($kind)) }}" loading="lazy" class="h-16 w-16 rounded-[3px] border border-rule object-cover">
                            </a>
                            @can('issue.manage')
                                <form method="POST" action="{{ route('issue-photos.destroy', $photo) }}" onsubmit="return confirm(@js(__('Remove this photo?')))">
                                    @csrf @method('DELETE')
                                    <button class="link text-[11px]">{{ __('Remove') }}</button>
                                </form>
                            @endcan
                        </div>
                    @empty
                        <p class="text-ink-3">{{ __('No photo yet.') }}</p>
                    @endforelse
                </div>
                @can('issue.manage')
                    <form method="POST" action="{{ route('issue-photos.store', $issue) }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $kind }}">
                        <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required class="input max-w-48 py-1 text-xs" aria-label="{{ __('Add photo') }}: {{ __(ucfirst($kind)) }}">
                        <button class="btn btn-ghost py-1 text-xs">{{ __('Add') }}</button>
                    </form>
                @endcan
            </div>
        @endforeach
    </div>
</details>
