{{-- Reloads the page when the run moves on. Nothing is drawn. --}}
<div x-data x-init="
    const phase = @js(\App\Http\Controllers\Agents\AgentRunController::phase($run));
    const check = setInterval(async () => {
        const response = await fetch(@js(route('agent-runs.status', $run)), { headers: { Accept: 'application/json' } });
        if (response.ok && (await response.json()).phase !== phase) { clearInterval(check); location.reload(); }
    }, 2500);
"></div>
