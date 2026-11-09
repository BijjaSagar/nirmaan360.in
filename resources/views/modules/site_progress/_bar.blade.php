@php
    $width = \App\Services\SiteProgress\ProgressReport::bar($percent);
@endphp
<div class="h-2.5 w-full overflow-hidden rounded-[2px] bg-paper-2" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $width }}">
    <div class="h-full {{ $tone ?? 'bg-moss' }}" style="width: {{ $width }}%"></div>
</div>
