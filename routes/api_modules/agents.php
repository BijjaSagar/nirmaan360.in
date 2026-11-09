<?php

use App\Http\Controllers\Agents\AgentApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:ai_agents')->group(function () {
    Route::middleware('can:agents.view')->group(function () {
        Route::get('/agents', [AgentApiController::class, 'index']);
        Route::get('/agent-runs/{run}', [AgentApiController::class, 'run']);
        Route::get('/credits', [AgentApiController::class, 'credits']);
    });

    Route::middleware('can:agents.use')->group(function () {
        Route::post('/agents/{agent}/runs', [AgentApiController::class, 'start'])->middleware('throttle:30,1');
        Route::post('/agent-runs/{run}/cancel', [AgentApiController::class, 'cancel']);
        Route::get('/agent-chats', [AgentApiController::class, 'conversations']);
        Route::get('/agent-chats/{conversation}', [AgentApiController::class, 'conversation']);
    });

    // canAct decides who may act, as on every other approval.
    Route::post('/agent-runs/{run}/approve', [AgentApiController::class, 'approve']);
});
