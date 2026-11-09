@inject('agentGateway', 'App\Services\Agents\ModelGateway')
@unless ($agentGateway->isConnected())
    <div class="mb-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">
        {{ __('AI agents are not connected. No run can start until a platform admin saves an API key.') }}
    </div>
@endunless
