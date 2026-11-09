<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Models\WorkerAdvance;
use App\Services\Payroll\AdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdvanceController extends Controller
{
    public function __construct(private AdvanceService $service) {}

    public function index(): View
    {
        return view('modules.payroll.advances', [
            'advances' => WorkerAdvance::with('worker')->withSum('recoveries as recovered_amount', 'amount')->latest('given_on')->latest('id')->paginate(30),
            'workers' => Worker::where('is_active', true)->orderBy('name')->get(['id', 'name', 'trade']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->give($request->validate($this->service->rules()), $request->user());

        return back()->with('success', __('Advance recorded. It will be taken back from the next wage runs.'));
    }

    public function destroy(WorkerAdvance $advance): RedirectResponse
    {
        $this->service->remove($advance);

        return back()->with('success', __('Advance removed.'));
    }
}
