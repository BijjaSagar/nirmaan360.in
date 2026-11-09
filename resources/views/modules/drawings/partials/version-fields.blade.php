<x-field :label="__('Revision')" name="revision"><input id="revision" name="revision" value="{{ old('revision', $defaultRevision ?? 'R0') }}" maxlength="16" class="input" required></x-field>
<x-field :label="__('Status')" name="status">
    <select id="status" name="status" class="input">
        @foreach (config('modules.drawings.version_statuses') as $status)
            <option value="{{ $status }}" @selected(old('status', 'for_information') === $status)>{{ __(\Illuminate\Support\Str::headline($status)) }}</option>
        @endforeach
    </select>
</x-field>
<x-field :label="__('File')" name="file" :hint="__('PDF or image files open in the viewer. Word, Excel and CAD files can be stored and downloaded. Up to 50 MB.')" class="sm:col-span-2"><input id="file" name="file" type="file" class="input" required></x-field>
<x-field :label="__('Note')" name="note" class="sm:col-span-4"><input id="note" name="note" value="{{ old('note') }}" maxlength="500" class="input"></x-field>
