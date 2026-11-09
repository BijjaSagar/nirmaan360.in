<form method="POST" action="{{ route('approvals.act', $approval) }}" class="mt-3 space-y-2">
    @csrf
    <textarea name="remark" rows="2" class="input" placeholder="{{ __('Remark (needed to return or reject)') }}" aria-label="{{ __('Remark') }}"></textarea>
    <div class="flex flex-wrap gap-2">
        <button name="action" value="approve" class="btn btn-primary">{{ __('Approve') }}</button>
        <button name="action" value="return" class="btn btn-ghost">{{ __('Return') }}</button>
        <button name="action" value="reject" class="btn btn-danger">{{ __('Reject') }}</button>
    </div>
</form>
