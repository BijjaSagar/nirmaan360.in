@foreach ($tree[$parent] ?? [] as $folder)
    <a href="{{ route('drawings.register.index', [$project, 'folder' => $folder->id]) }}"
       class="flex items-center justify-between gap-2 rounded-[3px] py-1 pr-2 text-sm hover:bg-paper {{ $folderId === $folder->id ? 'bg-paper-2 font-semibold' : '' }}"
       style="padding-left: {{ 8 + $depth * 14 }}px">
        <span class="truncate">{{ $folder->name }}</span>
        <span class="num text-xs text-ink-3">{{ $folder->drawings_count }}</span>
    </a>
    @include('modules.drawings.partials.folder-tree', ['parent' => $folder->id, 'depth' => $depth + 1])
@endforeach
