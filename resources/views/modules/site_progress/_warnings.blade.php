@if (session('site_progress_warning'))
    <div class="mb-4 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2.5 text-sm font-medium text-ink" role="alert">
        @foreach ((array) session('site_progress_warning') as $message)
            <p>{{ $message }}</p>
        @endforeach
    </div>
@endif
