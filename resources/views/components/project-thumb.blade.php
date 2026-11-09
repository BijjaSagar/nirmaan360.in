@props(['project', 'size' => 'h-9 w-9'])
@if ($project->image_path)
    <img src="{{ route('projects.image', [$project, 'v' => $project->imageVersion()]) }}" alt="" loading="lazy" decoding="async" {{ $attributes->class([$size, 'shrink-0 rounded-md object-cover']) }}>
@else
    <span aria-hidden="true" {{ $attributes->class([$size, 'inline-grid shrink-0 place-items-center rounded-md bg-paper-2 text-xs font-semibold text-ink-3']) }}>{{ mb_strtoupper(mb_substr($project->name, 0, 1)) }}</span>
@endif
