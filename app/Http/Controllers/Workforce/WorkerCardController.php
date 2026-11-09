<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Services\Workforce\WorkerCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkerCardController extends Controller
{
    public function __construct(private WorkerCardService $cards) {}

    /** Printable QR cards. One worker with ?worker=, otherwise everyone who is active. */
    public function index(Request $request): View
    {
        $workerId = $request->validate(['worker' => ['nullable', 'integer']])['worker'] ?? null;

        $workers = Worker::where('is_active', true)
            ->when($workerId, fn ($query) => $query->whereKey($workerId))
            ->orderBy('name')
            ->get();

        return view('modules.workforce.cards', [
            'workers' => $workers,
            'cards' => $workers->mapWithKeys(fn (Worker $worker) => [$worker->id => $this->cards->svg($worker)]),
            'single' => $workerId !== null,
        ]);
    }

    /** Cancel a worker's printed card, for a lost card. The next print carries a new code. */
    public function reissue(Worker $worker): RedirectResponse
    {
        $this->cards->reissue($worker);

        return redirect()->route('workforce.cards', ['worker' => $worker->id])->with('success', __('Card replaced. The old card no longer works. Print the new one.'));
    }
}
