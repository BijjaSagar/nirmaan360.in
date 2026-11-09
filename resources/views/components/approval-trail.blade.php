@props(['approvals'])
@inject('approvalService', 'App\Services\ApprovalService')
<div class="card">
    <div class="card-head"><h2 class="card-title">{{ __('Approval trail') }}</h2></div>
    @if ($approvals->isEmpty())
        <p class="px-4 py-5 text-sm text-ink-3">{{ __('Not submitted yet.') }}</p>
    @endif
    @foreach ($approvals as $approval)
        <div class="border-b border-rule px-4 py-3 last:border-b-0">
            <div class="flex flex-wrap items-center gap-2">
                <x-stamp :status="$approval->status" />
                <span class="flex flex-wrap items-center gap-1 text-xs text-ink-3">
                    @foreach ($approval->levels as $index => $role)
                        <span class="{{ $approval->status === 'IN_REVIEW' && $index + 1 === $approval->current_level ? 'font-semibold text-neel' : '' }}">{{ __(config("buildos.approval_level_roles.{$role}", $role)) }}</span>
                        @unless ($loop->last)<span aria-hidden="true">→</span>@endunless
                    @endforeach
                </span>
            </div>
            <ol class="mt-2 space-y-1.5">
                @foreach ($approval->steps as $step)
                    <li class="text-[13px]">
                        <span class="font-semibold">{{ $step->actorName() }}</span>
                        <span class="text-ink-3">· {{ __(ucfirst($step->action)) }} · <span class="num text-[11px]">{{ $step->acted_at->format('d M Y H:i') }}</span></span>
                        @if ($step->remark)
                            <p class="mt-0.5 border-l-2 border-haldi pl-2 text-ink-2">{{ $step->remark }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if ($approvalService->canAct($approval, auth()->user()))
                @include('approvals._act', ['approval' => $approval])
            @elseif ($approval->status === 'IN_REVIEW')
                <p class="mt-2 text-xs text-ink-3">{{ __('Waiting for: :role', ['role' => __($approval->currentRoleLabel())]) }}</p>
            @endif
        </div>
    @endforeach
</div>
