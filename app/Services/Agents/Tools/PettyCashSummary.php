<?php

namespace App\Services\Agents\Tools;

use App\Models\PettyCashWallet;

class PettyCashSummary extends Tool
{
    public function name(): string
    {
        return 'petty_cash_summary';
    }

    public function description(): string
    {
        return 'Petty cash wallets on a project with their current balances.';
    }

    public function schema(): array
    {
        return $this->objectSchema(['project_id' => $this->projectId()], ['project_id']);
    }

    public function permission(): string
    {
        return 'petty_cash.view';
    }

    public function feature(): ?string
    {
        return 'petty_cash';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);

        $wallets = PettyCashWallet::where('project_id', $project->id)->orderBy('name')->get();

        return new ToolResult(
            ['wallets' => $wallets->map(fn (PettyCashWallet $wallet) => ['id' => $wallet->id, 'name' => $wallet->name, 'balance' => $wallet->balance()])->all()],
            [ToolResult::source('petty_cash', $project->id, 'Petty cash · '.$project->name, route('petty.index', $project, false))],
        );
    }
}
