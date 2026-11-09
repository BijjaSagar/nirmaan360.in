<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WageRun;
use App\Models\WageRunLine;
use App\Services\Payroll\WageRunService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WageRunController extends Controller
{
    public function __construct(private WageRunService $service) {}

    public function index(Request $request): View
    {
        return view('modules.payroll.runs.index', [
            'runs' => WageRun::with('project')->latest('id')->paginate(25),
            'projects' => Project::whereIn('status', ['planning', 'active', 'on_hold'])->orderBy('name')->get(['id', 'name', 'code']),
            'terms' => $this->service->terms($request->user()->organization),
            'scopes' => WageRunService::SCOPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from', 'before_or_equal:today'],
            'project_id' => ['nullable', 'integer', org_exists('projects')->whereNull('deleted_at')],
            'worker_scope' => ['required', Rule::in(WageRunService::SCOPES)],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $run = $this->service->create($data, $request->user());

        return redirect()->route('payroll.runs.show', $run)->with('success', __('Wage run calculated as a draft. Check it, then have it approved.'));
    }

    public function show(WageRun $run): View
    {
        $run->load('lines', 'project', 'preparer', 'approver');

        return view('modules.payroll.runs.show', [
            'run' => $run,
            'stale' => $this->service->isStale($run),
            'canApprove' => $run->status === 'DRAFT' && (auth()->user()->isApprovalOverride() || $run->prepared_by !== auth()->id()),
        ]);
    }

    public function recalculate(WageRun $run): RedirectResponse
    {
        $this->service->recalculate($run);

        return back()->with('success', __('Wage run calculated again from the latest attendance.'));
    }

    public function approve(Request $request, WageRun $run): RedirectResponse
    {
        $this->service->approve($run, $request->user());

        return back()->with('success', __('Wage run approved and locked. Payslips are ready.'));
    }

    public function paid(Request $request, WageRun $run): RedirectResponse
    {
        $data = $request->validate(['payment_note' => ['nullable', 'string', 'max:190']]);

        $this->service->markPaid($run, $request->user(), $data['payment_note'] ?? null);

        return back()->with('success', __('Wage run marked as paid.'));
    }

    public function destroy(WageRun $run): RedirectResponse
    {
        $this->service->delete($run);

        return redirect()->route('payroll.runs.index')->with('success', __('Draft wage run removed.'));
    }

    public function updateTerms(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shift_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'ot_multiplier' => ['required', 'numeric', 'min:1', 'max:5'],
        ]);

        $this->service->saveTerms($request->user()->organization, $data);

        return back()->with('success', __('Overtime terms saved. They apply to new wage runs.'));
    }

    /** One worker's payslip. Only once the run is approved, because until then the numbers can still change. */
    public function payslip(WageRun $run, WageRunLine $line): Response
    {
        abort_unless($run->isLocked() && $line->wage_run_id === $run->id, 404);

        return $this->pdf($run, [$line->load('worker')], 'payslip-'.str_replace('/', '-', $run->number).'-'.$line->worker_id.'.pdf');
    }

    /** Every payslip in the run, one to a page. */
    public function payslips(WageRun $run): Response
    {
        abort_unless($run->isLocked(), 404);

        return $this->pdf($run, $run->lines()->with('worker')->get()->all(), 'payslips-'.str_replace('/', '-', $run->number).'.pdf');
    }

    private function pdf(WageRun $run, array $lines, string $filename): Response
    {
        $run->load('organization', 'project');

        return Pdf::loadView('modules.payroll.payslip', ['run' => $run, 'lines' => $lines])
            ->setPaper('a5', 'portrait')
            ->download($filename);
    }
}
