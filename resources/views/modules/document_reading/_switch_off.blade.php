{{-- Withdraws the company's agreement. Shown wherever the agreement is on record, whether or not a reader is available. --}}
<form method="POST" action="{{ route('ai-documents.update') }}" class="space-y-2 border-t border-rule pt-3">
    @csrf
    <input type="hidden" name="action" value="off">
    <p class="text-sm text-ink-3">{{ __('Switching off stops the readers from that moment: a document that is still being read is cancelled and its credits on hold are given back. Documents that were already read stay, and can still be checked and saved.') }}</p>
    <button class="btn btn-danger" onclick="return confirm(@js(__('Switch the readers off for your company? Documents that are still being read will be cancelled.')))">{{ __('Switch the readers off') }}</button>
</form>
