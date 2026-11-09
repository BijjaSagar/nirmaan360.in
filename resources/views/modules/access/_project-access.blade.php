{{-- $projects, $access ('all' or 'chosen'), $selected (list of project ids) --}}
<fieldset x-data="{ access: @js($access) }">
    <legend class="label">{{ __('Projects') }}</legend>
    <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <label class="flex items-center gap-1.5"><input type="radio" name="project_access" value="all" x-model="access"> {{ __('All projects') }}</label>
        <label class="flex items-center gap-1.5"><input type="radio" name="project_access" value="chosen" x-model="access"> {{ __('Only chosen projects') }}</label>
    </div>
    <div x-show="access === 'chosen'" x-cloak class="mt-2 grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($projects as $project)
            <label class="flex items-center gap-1.5 text-sm">
                <input type="checkbox" name="project_ids[]" value="{{ $project->id }}" @checked(in_array($project->id, $selected, true))>
                <span class="truncate"><span class="num text-xs text-ink-3">{{ $project->code }}</span> {{ $project->name }}</span>
            </label>
        @empty
            <p class="hint">{{ __('There are no projects yet.') }}</p>
        @endforelse
    </div>
    @error('project_ids')<p class="error">{{ $message }}</p>@enderror
    @error('project_ids.*')<p class="error">{{ $message }}</p>@enderror
</fieldset>
