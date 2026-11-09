<?php

return [
    'feature' => 'ai_agents',

    'plans' => ['business', 'enterprise'],

    // Owner and admin already have everything. agents.copilot is theirs alone.
    'permissions' => [
        'pm' => ['agents.view', 'agents.use', 'agents.manage', 'agents.credits', 'agent_action.approve'],
        'qs' => ['agents.view', 'agents.use'],
        'engineer' => ['agents.view', 'agents.use'],
        'store' => ['agents.view', 'agents.use'],
        'accounts' => ['agents.view', 'agents.use', 'agents.credits'],
    ],

    'nav' => [
        ['place' => 'company', 'group' => 'AI', 'label' => 'AI agents', 'route' => 'agents.index', 'active' => 'agents.*', 'can' => 'agents.view', 'order' => 10],
        ['place' => 'company', 'group' => 'AI', 'label' => 'Agent runs', 'route' => 'agent-runs.index', 'active' => 'agent-runs.*', 'can' => 'agents.view', 'order' => 20],
        ['place' => 'company', 'group' => 'AI', 'label' => 'Credits', 'route' => 'agent-credits.index', 'active' => 'agent-credits.*', 'can' => 'agents.credits', 'order' => 30],
        ['place' => 'project', 'label' => 'Ask my project', 'route' => 'agents.ask', 'active' => 'agents.ask', 'can' => 'agents.use', 'order' => 10],
    ],

    // US dollars per million tokens, at Anthropic's list price.
    'pricing' => [
        'claude-sonnet-5-5' => ['input' => '2.00', 'output' => '10.00', 'cache_write' => '2.50', 'cache_read' => '0.20'],
        'claude-opus-5-5' => ['input' => '4.00', 'output' => '20.00', 'cache_write' => '5.00', 'cache_read' => '0.20'],
        'claude-haiku-5-5' => ['input' => '0.10', 'output' => '0.50', 'cache_write' => '0.125', 'cache_read' => '0.01'],
    ],

    // Credits charged = dollars × usd_inr × margin ÷ credit_inr. The admin screen can change the first two.
    'usd_inr' => '90.00',
    'margin' => '1.30',
    'credit_inr' => '1.00',

    // Included each calendar month. Unused included credits lapse; bought credits do not.
    'plan_credits' => ['business' => 500, 'enterprise' => 2000],

    // Prices are in rupees before GST. GST follows the platform's subscription GST setting.
    'packs' => [
        'small' => ['credits' => 500, 'price' => '500.00'],
        'medium' => ['credits' => 2000, 'price' => '1900.00'],
        'large' => ['credits' => 5000, 'price' => '4500.00'],
    ],

    // Must stay under the queue's retry_after (90 by default), or a slow call gets run twice.
    'http_timeout' => 75,

    'max_tokens' => 6000,

    // How many earlier turns of a chat are sent back to the model.
    'history_turns' => 6,
];
