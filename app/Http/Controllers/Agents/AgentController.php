<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentInstall;
use App\Models\AgentRun;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\Autonomy;
use App\Services\Agents\CreditService;
use App\Services\Agents\ToolRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function __construct(private AgentRegistry $registry, private CreditService $credits) {}

    public function index(Request $request): View
    {
        $organization = $request->user()->organization;
        $agents = $this->registry->all();

        return view('modules.agents.index', [
            'agents' => $agents->map(fn ($agent) => [
                'agent' => $agent,
                'install' => $install = $this->registry->install($agent, $organization),
                'enabled' => $this->registry->isEnabled($install),
                'autonomy' => $this->registry->autonomy($install, $agent->currentVersion),
            ]),
            'wallet' => $this->credits->wallet($organization),
        ]);
    }

    public function show(Request $request, string $agent, ToolRegistry $tools): View
    {
        $agent = $this->registry->find($agent);
        $user = $request->user();
        $install = $this->registry->install($agent, $user->organization);
        $version = $agent->currentVersion;

        return view('modules.agents.show', [
            'agent' => $agent,
            'version' => $version,
            'versions' => $agent->versions()->take(10)->get(),
            'install' => $install,
            'enabled' => $this->registry->isEnabled($install),
            'autonomy' => $this->registry->autonomy($install, $version),
            'autonomyOptions' => Autonomy::upTo($version->max_autonomy),
            'limits' => $this->registry->limits($install, $version),
            'tools' => array_values(array_filter(array_map(fn ($name) => $tools->find($name), $version->tools))),
            'runs' => AgentRun::with('actor')->where('agent_id', $agent->id)->visibleTo($user)->latest('id')->take(10)->get(),
        ]);
    }

    public function update(Request $request, string $agent): RedirectResponse
    {
        $agent = $this->registry->find($agent);
        $version = $agent->currentVersion;

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'autonomy' => ['required', Rule::in(array_keys(Autonomy::upTo($version->max_autonomy)))],
            'max_steps' => ['required', 'integer', 'min:1', 'max:'.$version->max_steps],
            'max_seconds' => ['required', 'integer', 'min:10', 'max:'.$version->max_seconds],
            'max_credits' => ['required', 'numeric', 'gt:0', 'max:'.$version->max_credits],
            'daily_brief' => ['sometimes', 'boolean'],
        ]);

        // Only the Copilot has a daily brief. Off by default, because each one spends credits.
        $data['daily_brief'] = $agent->key === 'owners_copilot' && ($data['daily_brief'] ?? false);

        AgentInstall::updateOrCreate(
            ['organization_id' => $request->user()->organization_id, 'agent_id' => $agent->id],
            $data,
        );

        return back()->with('success', __('Agent settings saved.'));
    }
}
