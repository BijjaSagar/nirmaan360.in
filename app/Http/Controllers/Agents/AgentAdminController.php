<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentStep;
use App\Models\AuditLog;
use App\Services\Agents\CostMeter;
use App\Services\Agents\ModelGateway;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/** Where the platform owner connects the AI service and sees what each company uses. */
class AgentAdminController extends Controller
{
    public function __construct(private PlatformSettings $settings, private ModelGateway $gateway, private CostMeter $meter) {}

    public function edit(): View
    {
        return view('modules.agents.admin', [
            'connected' => $this->gateway->isConnected(),
            'usdInr' => $this->meter->usdToInr(),
            'margin' => $this->meter->margin(),
            'usage' => AgentStep::withoutGlobalScopes()
                ->join('organizations', 'organizations.id', '=', 'agent_steps.organization_id')
                ->where('agent_steps.created_at', '>=', now('Asia/Kolkata')->startOfMonth())
                ->selectRaw('organizations.name as company, COUNT(*) as calls, SUM(input_tokens + cache_read_tokens + cache_write_tokens) as input_total, SUM(output_tokens) as output_total, SUM(cost_usd) as cost_usd, SUM(credits) as credits')
                ->groupBy('organizations.name')
                ->orderByDesc('credits')
                ->take(50)
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'anthropic_api_key' => ['nullable', 'string', 'max:255'],
            'ai_usd_inr' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'ai_margin' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);

        // A blank key box means "keep what is saved". The key is never shown back.
        if (blank($data['anthropic_api_key'] ?? null)) {
            unset($data['anthropic_api_key']);
        }

        if ($request->boolean('clear_key')) {
            $data['anthropic_api_key'] = null;
        }

        $this->settings->set($data);
        AuditLog::record('platform.settings_changed', $request->user(), null, ['settings' => array_keys($data)]);

        return back()->with('success', __('AI settings saved.'));
    }

    public function test(): RedirectResponse
    {
        try {
            $works = $this->gateway->keyWorks();
        } catch (Throwable $exception) {
            Log::warning('AI key test failed', ['error' => $exception->getMessage()]);
            $works = false;
        }

        return $works
            ? back()->with('success', __('The AI service accepted this key.'))
            : back()->withErrors(['anthropic_api_key' => __('The AI service did not accept this key. Check it and try again.')]);
    }
}
