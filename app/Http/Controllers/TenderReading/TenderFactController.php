<?php

namespace App\Http\Controllers\TenderReading;

use App\Http\Controllers\Controller;
use App\Models\CrmTender;
use App\Models\CrmTenderFact;
use App\Services\DocumentReading\ReadRefused;
use App\Services\TenderReading\TenderFactReview;
use App\Services\TenderReading\TenderSheet;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The tender sheet, and correcting, confirming or rejecting one fact on it. */
class TenderFactController extends Controller
{
    public function __construct(private TenderSheet $sheet, private TenderFactReview $review) {}

    public function index(Request $request, CrmTender $tender): View
    {
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
