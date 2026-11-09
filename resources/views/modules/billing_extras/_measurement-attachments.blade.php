<section class="card">
    <div class="card-head"><h2 class="card-title">{{ __('Photos and sketches') }}</h2></div>
    <div class="p-4">
        @if ($entry->attachments->isEmpty())
            <p class="text-sm text-ink-3">{{ __('Nothing attached.') }}</p>
        @else
            <div class="flex flex-wrap gap-3">
                @foreach ($entry->attachments as $attachment)
                    <div class="w-24 text-xs">
                        <a href="{{ route('measurement-attachments.show', $attachment) }}" target="_blank" rel="noopener" class="block">
                            @if ($attachment->isImage())
                                <img src="{{ route('measurement-attachments.show', $attachment) }}" alt="{{ $attachment->caption ?: __(ucfirst($attachment->kind)) }}" loading="lazy" class="h-24 w-24 rounded-[3px] border border-rule object-cover">
                            @else
                                <span class="flex h-24 w-24 items-center justify-center rounded-[3px] border border-rule font-semibold text-ink-2">PDF</span>
                            @endif
                        </a>
                        <p class="mt-1 text-ink-3">{{ __(ucfirst($attachment->kind)) }}</p>
                        @if ($attachment->caption)<p class="truncate">{{ $attachment->caption }}</p>@endif
                        @can('measurement.create')
                            @if ($entry->isEditable())
                                <form method="POST" action="{{ route('measurement-attachments.destroy', $attachment) }}" onsubmit="return confirm(@js(__('Remove this attachment?')))">
                                    @csrf @method('DELETE')
                                    <button class="link">{{ __('Remove') }}</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif

        @can('measurement.create')
            @if ($entry->isEditable())
                <form method="POST" action="{{ route('measurement-attachments.store', $entry) }}" enctype="multipart/form-data" class="mt-4 grid gap-3 border-t border-rule pt-4 sm:grid-cols-4">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <x-field :label="__('Type')" name="kind">
                        <select id="kind" name="kind" class="input">
                            <option value="photo" @selected(old('kind') === 'photo')>{{ __('Photo') }}</option>
                            <option value="sketch" @selected(old('kind') === 'sketch')>{{ __('Sketch') }}</option>
                        </select>
                    </x-field>
                    <x-field :label="__('File')" name="file" class="sm:col-span-2" :hint="__('Photos: JPG, PNG or WebP. Sketches may also be PDF. Up to 8 MB.')">
                        <input id="file" name="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required class="input">
                    </x-field>
                    <x-field :label="__('Caption')" name="caption"><input id="caption" name="caption" value="{{ old('caption') }}" maxlength="255" class="input"></x-field>
                    <div class="sm:col-span-4"><button class="btn btn-ghost">{{ __('Attach') }}</button></div>
                </form>
            @elseif ($entry->status === 'APPROVED')
                <p class="mt-3 text-xs text-ink-3">{{ __('Attachments are locked with the approved entry.') }}</p>
            @else
                <p class="mt-3 text-xs text-ink-3">{{ __('Attachments cannot change while the entry is under review.') }}</p>
            @endif
        @endcan
    </div>
</section>
