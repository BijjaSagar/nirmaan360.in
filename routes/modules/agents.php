<?php

use App\Http\Controllers\Agents\AgentAdminController;
use App\Http\Controllers\Agents\AgentChatController;
use App\Http\Controllers\Agents\AgentController;
use App\Http\Controllers\Agents\AgentCreditController;
use App\Http\Controllers\Agents\AgentDraftController;
use App\Http\Controllers\Agents\AgentRunController;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:ai_agents')->group(function () {
    Route::middleware('can:agents.view')->group(function () {
        Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
        Route::get('/agents/{agent}', [AgentController::class, 'show'])->name('agents.show');
        Route::get('/agent-runs', [AgentRunController::class, 'index'])->name('agent-runs.index');
        Route::get('/agent-runs/{run}', [AgentRunController::class, 'show'])->name('agent-runs.show');
        Route::get('/agent-runs/{run}/status', [AgentRunController::class, 'status'])->name('agent-runs.status');
    });

    Route::patch('/agents/{agent}/settings', [AgentController::class, 'update'])->middleware('can:agents.manage')->name('agents.settings');

    Route::middleware('can:agents.use')->group(function () {
        Route::get('/agents/{agent}/chat', [AgentChatController::class, 'create'])->name('agents.chat');
        Route::post('/agents/{agent}/chat', [AgentChatController::class, 'send'])->name('agents.chat.send')->middleware('throttle:30,1');
        Route::get('/agent-chats/{conversation}', [AgentChatController::class, 'show'])->name('agents.chats.show');
        Route::get('/projects/{project}/ask', [AgentChatController::class, 'ask'])->name('agents.ask');
        Route::post('/agent-runs/{run}/cancel', [AgentRunController::class, 'cancel'])->name('agent-runs.cancel');
    });

    Route::middleware('can:agents.credits')->group(function () {
        Route::get('/agent-credits', [AgentCreditController::class, 'index'])->name('agent-credits.index');
        Route::post('/agent-credits/checkout', [AgentCreditController::class, 'checkout'])->name('agent-credits.checkout')->middleware('throttle:10,1');
        Route::post('/agent-credits/confirm', [AgentCreditController::class, 'confirm'])->name('agent-credits.confirm');
    });

    Route::middleware(['feature:site', 'can:dpr.create'])->group(function () {
        Route::get('/agent-drafts/dprs/{dpr}/edit', [AgentDraftController::class, 'edit'])->name('agents.drafts.dpr.edit');
        Route::patch('/agent-drafts/dprs/{dpr}', [AgentDraftController::class, 'update'])->name('agents.drafts.dpr.update');
    });
});

// Platform staff only. Everyone else gets a 404.
Route::prefix('admin/ai-agents')->name('agents.admin.')->middleware(EnsurePlatformAdmin::class)->controller(AgentAdminController::class)->group(function () {
    Route::get('/', 'edit')->name('edit');
    Route::patch('/', 'update')->name('update');
    Route::post('/test', 'test')->name('test')->middleware('throttle:10,1');
});
