<?php

namespace App\Http\Controllers\BillingExtras;

use App\Http\Controllers\Controller;
use App\Models\BillNote;
use App\Models\RaBill;
use App\Services\BillingExtras\BillNoteService;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BillNoteController extends Controller
{
    public function __construct(private BillNoteService $service) {}

    public function create(RaBill $bill): View
    {
        abort_unless($bill->acceptsNotes(), 409, __('Notes can only be raised against approved or paid bills.'));

        return view('modules.billing_extras.notes.create', [
            'bill' => $bill->load('party'),
            'project' => $bill->project,
            'reducible' => $this->service->reducibleAmount($bill),
            'gstPct' => $bill->hasGst() ? $bill->terms['gst_pct'] ?? 0 : 0,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, RaBill $bill): RedirectResponse
    {
        $note = $this->service->create($bill, $request->validate($this->service->rules()), $request->user());

        return redirect()->route('bill-notes.show', $note)->with('success', __('Note saved as draft. Submit it for approval.'));
    }

    public function show(BillNote $note): View
    {
        return view('modules.billing_extras.notes.show', [
            'note' => $note->load('bill', 'party', 'creator', 'approvals.steps.user'),
            'project' => $note->project,
        ]);
    }

    public function submit(Request $request, BillNote $note): RedirectResponse
    {
        $this->service->submit($note, $request->user());

        return back()->with('success', __('Note submitted for approval.'));
    }

    public function cancel(BillNote $note): RedirectResponse
    {
        $this->service->cancel($note);

        return back()->with('success', __('Note cancelled.'));
    }

    public function pdf(Request $request, BillNote $note): Response
    {
        // A note PDF is nothing but amounts, so there is no quantities-only version of it.
        abort_unless(Money::visible($request->user()), 403, Money::refusal());

        $note->load('bill', 'party', 'project', 'organization');

        return Pdf::loadView('modules.billing_extras.pdf.bill-note', ['note' => $note])
            ->setPaper('a4')
            ->download($note->number.'.pdf');
    }
}
