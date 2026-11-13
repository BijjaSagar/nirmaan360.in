@php
    // A document whose rule stops this final approval, such as a contractor payment past the project's limit:
    // only the owner may pass it, with a written reason, and nobody else is offered Approve.
    $document = $approval->approvable;
    $guard = $document && $approval->isFinalLevel() && method_exists($document, 'approvalGuard') ? $document->approvalGuard() : null;
    $blocked = $guard !== null && app($guard)->blocks($document);
    $override = $blocked && auth()->user()->isOwner();
@endphp
<form method="POST" action="{{ route('approvals.act', $approval) }}" class="mt-3 space-y-2">
    @csrf
    <textarea name="remark" rows="2" class="input" placeholder="{{ __('Remark (needed to return or reject)') }}" aria-label="{{ __('Remark') }}"></textarea>
    @if ($override)
        <div>
            <label for="override_reason_{{ $approval->id }}" class="text-xs font-semibold text-laterite">{{ __('Owner override: why may this payment go ahead of the work done?') }}</label>
            <textarea id="override_reason_{{ $approval->id }}" name="override_reason" rows="2" maxlength="1000" class="input mt-1" placeholder="{{ __('At least one full sentence. It is kept with the payment and in the audit log.') }}"></textarea>
        </div>
    @endif
    <div class="flex flex-wrap gap-2">
        @if (! $blocked || $override)
            <button name="action" value="approve" class="btn btn-primary">{{ $override ? __('Approve with override') : __('Approve') }}</button>
        @endif
        <button name="action" value="return" class="btn btn-ghost">{{ __('Return') }}</button>
        <button name="action" value="reject" class="btn btn-danger">{{ __('Reject') }}</button>
    </div>
</form>
