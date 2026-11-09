<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\Worker;
use App\Services\WorkerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkerController extends Controller
{
    public function __construct(private WorkerService $service) {}

    public function index(): View
    {
        return view('attendance.workers', [
            'workers' => Worker::with('contractor')->orderBy('name')->paginate(50),
            'contractors' => Party::where('type', 'subcontractor')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->create($request->validate($this->service->rules()));

        return back()->with('success', __('Worker added.'));
    }

    public function update(Request $request, Worker $worker): RedirectResponse
    {
        $worker->update([...$request->validate($this->service->rules()), 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', __('Worker updated.'));
    }
}
