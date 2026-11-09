<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\Worker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkerController extends Controller
{
    public function index(): View
    {
        return view('attendance.workers', [
            'workers' => Worker::with('contractor')->orderBy('name')->paginate(50),
            'contractors' => Party::where('type', 'subcontractor')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Worker::create($this->validated($request));

        return back()->with('success', __('Worker added.'));
    }

    public function update(Request $request, Worker $worker): RedirectResponse
    {
        $worker->update([...$this->validated($request), 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', __('Worker updated.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'trade' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(['own', 'contractor'])],
            'contractor_id' => ['nullable', 'required_if:type,contractor', org_exists('parties')],
            'daily_wage' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);
    }
}
