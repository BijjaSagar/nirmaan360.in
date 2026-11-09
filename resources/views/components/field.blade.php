@props(['label', 'name' => null, 'hint' => null])
<div {{ $attributes }}>
    <label class="label" @if ($name) for="{{ $name }}" @endif>{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <p class="hint">{{ $hint }}</p>
    @endif
    @if ($name)
        @error($name)
            <p class="error">{{ $message }}</p>
        @enderror
    @endif
</div>
