{{--
    The "Add tender" form, opened from a line on the BOQ page. It makes a tender holding that line and then goes to
    the line's tender page, where each contractor's quote is added. A refused form opens again with what was typed.
--}}
@php
    use Illuminate\Support\Str;

    // Rebuilt from the line on this page, never from what was posted, so the form can only go back to its own line.
    $sentFor = old('form') === 'boq-tender' && is_scalar(old('tender_item')) ? $items->firstWhere('id', (int) old('tender_item')) : null;
    $reopen = $sentFor ? [
        'url' => route('boq.tenders.store', $sentFor),
        'item' => $sentFor->id,
        'uuid' => Str::isUuid((string) old('client_uuid')) ? (string) old('client_uuid') : (string) Str::uuid(),
        'code' => $sentFor->code,
        'description' => Str::limit($sentFor->description, 140),
        'title' => (string) old('title'),
        'due_on' => (string) old('due_on'),
        'scope' => (string) old('scope'),
    ] : null;
@endphp
<div x-init="tender = @js($reopen)" x-show="tender" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-ink/50 p-4" @keydown.escape.window="tender = null">
    <template x-if="tender">
        <div class="card mx-auto my-4 w-full max-w-lg" role="dialog" aria-modal="true" aria-labelledby="add-tender-title" @click.outside="tender = null">
            <form method="POST" :action="tender.url">
                @csrf
                <input type="hidden" name="form" value="boq-tender">
                <input type="hidden" name="client_uuid" :value="tender.uuid">
                <input type="hidden" name="tender_item" :value="tender.item">
                <div class="card-head"><h2 id="add-tender-title" class="card-title">{{ __('Add tender') }}</h2></div>
                <div class="grid gap-3 p-4">
                    <p class="text-sm text-ink-2"><span class="num" x-text="tender.code"></span> · <span x-text="tender.description"></span></p>
                    <div>
                        <label class="label" for="tender_title">{{ __('Title') }}</label>
                        <input id="tender_title" name="title" x-model="tender.title" maxlength="160" class="input" required>
                        @error('title')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label" for="tender_due_on">{{ __('Quotes needed by') }}</label>
                        <input id="tender_due_on" name="due_on" type="date" x-model="tender.due_on" class="input">
                        @error('due_on')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label" for="tender_scope">{{ __('Scope') }}</label>
                        <textarea id="tender_scope" name="scope" rows="2" x-model="tender.scope" class="input"></textarea>
                        @error('scope')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @error('boq_item_ids')<p class="error">{{ $message }}</p>@enderror
                    <p class="text-xs text-ink-3">
                        {{ __('Next you add each contractor\'s quote for this line.') }}
                        <a href="{{ route('contractor-control.tenders.create', $project) }}" class="link">{{ __('Put several BOQ lines in one tender') }}</a>
                    </p>
                </div>
                <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
                    <button type="button" class="btn btn-ghost" @click="tender = null">{{ __('Cancel') }}</button>
                    <button class="btn btn-primary">{{ __('Create tender') }}</button>
                </div>
            </form>
        </div>
    </template>
</div>
