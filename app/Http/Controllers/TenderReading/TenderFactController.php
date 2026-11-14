<?php

namespace App\Http\Controllers\TenderReading;

use App\Http\Controllers\Controller;
use App\Models\CrmTender;
use App\Models\CrmTenderFact;
use App\Models\CrmTenderPayment;
use App\Services\DocumentReading\ReadRefused;
use App\Services\TenderReading\TenderFactApply;
use App\Services\TenderReading\TenderFactReview;
use App\Services\TenderReading\TenderSheet;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The tender sheet, and correcting, confirming, rejecting or putting to use one fact on it. */
class TenderFactController extends Controller
{
    public function __construct(private TenderSheet $sheet, private TenderFactReview $review, private TenderFactApply $apply) {}

    public function index(Request $request, CrmTender $tender): View
    {
        abort_unless($this->sheet->open($tender, $request->user()), 404);

        return view('modules.tender_reading.sheet', [
            'tender' => $tender,
            'sheet' => $this->sheet->build($tender, $request->user()),
        ]);
    }

    public function update(Request $request, CrmTenderFact $fact): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['value' => ['required', 'string', 'max:500']]);

        return $this->answer($request, $fact, fn () => $this->review->edit($fact, $data['value'], $request->user()), __('Value corrected. Confirm it with its page beside it.'));
    }

    /** "viewed" is sent by the sheet only while the fact's page is drawn beside it. */
    public function confirm(Request $request, CrmTenderFact $fact): JsonResponse|RedirectResponse
    {
        $request->validate(['viewed' => ['nullable', 'boolean']]);

        return $this->answer($request, $fact, fn () => $this->review->confirm($fact, $request->boolean('viewed'), $request->user()), __('Fact confirmed.'));
    }

    public function reject(Request $request, CrmTenderFact $fact): JsonResponse|RedirectResponse
    {
        return $this->answer($request, $fact, fn () => $this->review->reject($fact, $request->user()), __('Fact rejected.'));
    }

    /**
     * Puts one confirmed fact to use: into the tender's field, as a follow-up, or as a planned
     * payment. A payment also needs the right to record EMD and fees, as the CRM's own payment form does.
     */
    public function apply(Request $request, CrmTenderFact $fact): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(TenderFactApply::TARGETS)],
            'mode' => ['nullable', 'required_if:target,'.TenderFactApply::PAYMENT, Rule::in(CrmTenderPayment::MODES)],
            'client_uuid' => ['nullable', 'uuid'],
        ], [
            'mode.required_if' => __('Choose how the payment will be made.'),
        ]);

        abort_if($data['target'] === TenderFactApply::PAYMENT && ! $request->user()->can('crm.emd'), 403);

        $back = route('tender-facts.index', $fact->tender_id).'#fact-'.$fact->id;

        try {
            $done = $this->apply->apply($fact, $data['target'], $request->user(), $data['mode'] ?? null, $data['client_uuid'] ?? null);
        } catch (ReadRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
            }

            return redirect()->to($back)->withErrors(['fact' => $refused->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $done['fact']->id,
                'status' => $done['fact']->status,
                'applied_to' => array_keys($done['fact']->uses()),
                'target' => $data['target'],
                'record' => $done['record'] === null ? null : ['type' => $done['record']->getMorphClass(), 'id' => (int) $done['record']->getKey()],
                'repeated' => $done['repeated'],
            ], $done['repeated'] ? 200 : 201);
        }

        return redirect()->to($back)->with('success', match ($data['target']) {
            TenderFactApply::FIELD => __('Put on the tender.'),
            TenderFactApply::TASK => __('Follow-up added.'),
            default => __('Planned payment added.'),
        });
    }

    /** @param  Closure(): CrmTenderFact  $step */
    private function answer(Request $request, CrmTenderFact $fact, Closure $step, string $done): JsonResponse|RedirectResponse
    {
        $back = route('tender-facts.index', $fact->tender_id).'#fact-'.$fact->id;

        try {
            $fact = $step();
        } catch (ReadRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
            }

            return redirect()->to($back)->withErrors(['fact' => $refused->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $fact->id, 'status' => $fact->status, 'edited' => (bool) $fact->edited, 'conflict' => $fact->conflict_group !== null]);
        }

        return redirect()->to($back)->with('success', $done);
    }
}
