{{-- Files on a record. Expects $owner, $type (the URL segment), $ability (permission prefix) and $locked. --}}
<section class="card">
    <div class="card-head"><h2 class="card-title">{{ __('Photos and documents') }}</h2></div>
    @if ($owner->attachments->isNotEmpty())
        <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3">
            @foreach ($owner->attachments as $file)
                <a href="{{ route('qhs-files.show', $file) }}" target="_blank" rel="noopener" class="block text-xs">
                    @if ($file->isImage())
                        <img src="{{ route('qhs-files.show', $file) }}" alt="{{ $file->caption ?? $file->original_name }}" class="aspect-[4/3] w-full rounded-[3px] object-cover">
                    @else
                        <span class="flex aspect-[4/3] w-full items-center justify-center rounded-[3px] border border-rule bg-paper-2/60"><x-icon name="file" class="size-8 text-ink-3" /></span>
                    @endif
                    <span class="mt-1 block truncate text-ink-2">{{ $file->caption ?? $file->original_name }}</span>
                </a>
            @endforeach
        </div>
    @else
        <p class="px-4 py-4 text-sm text-ink-3">{{ __('Nothing attached yet.') }}</p>
    @endif
    @if (! $locked)
        @can($ability.'.manage')
            <form method="POST" action="{{ route('qhs-files.store', [$type, $owner->id]) }}" enctype="multipart/form-data" class="grid gap-3 border-t border-rule p-4 sm:grid-cols-[2fr_2fr_auto] sm:items-end">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <x-field :label="__('Photo or PDF')" name="file"><input id="file-{{ $type }}" name="file" type="file" accept="image/*,application/pdf" class="input" required></x-field>
                <x-field :label="__('Caption')" name="caption"><input id="caption-{{ $type }}" name="caption" class="input" maxlength="255"></x-field>
                <button class="btn btn-ghost">{{ __('Add file') }}</button>
            </form>
        @endcan
    @endif
</section>
