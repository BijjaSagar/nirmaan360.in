<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\Worker;
use App\Services\WorkerService;
use App\Support\Money;
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
        $this->service->create($this->validated($request));

        return back()->with('success', __('Worker added.'));
    }

    public function update(Request $request, Worker $worker): RedirectResponse
    {
        $worker->update([...$this->validated($request), 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', __('Worker updated.'));
    }

    private function validated(Request $request): array
    {
        $rules = $this->service->rules();

        // Wages are money: someone who may not see them cannot set them either.
        // A new worker takes the service default and an edited worker keeps the wage already on file.
        if (! Money::visible($request->user())) {
            unset($rules['daily_wage']);
        }

        return $request->validate($rules);
    }
}
