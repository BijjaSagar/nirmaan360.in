<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\BoqItemPresenter;
use App\Models\BoqItem;
use App\Services\BoqService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editing and removing a BOQ line from the field app. Online only: the same rules as the web BOQ page,
 * through the same BoqService, and every change lands in the BOQ history with its reason.
 */
class BoqItemApiController extends Controller
{
    public function __construct(private BoqService $boq) {}

    /**
     * PATCH /boq-items/{item}. Writing the same values again changes nothing and records nothing, so a repeat
     * is harmless. With `updated_at` from when the line was opened, a line someone changed since is a 409 that
     * carries the line as it is now.
     */
    public function update(Request $request, BoqItem $item): JsonResponse
    {
        $rules = [...$this->boq->rules(), ...$this->boq->reasonRules(), 'updated_at' => ['nullable', 'date']];
        // A line's section is changed on the website. Rates are money: someone who may not see them keeps the rate.
        unset($rules['section']);

        if (! Money::visible($request->user())) {
            unset($rules['rate']);
        }

        $data = $request->validate($rules);

        if (filled($data['updated_at'] ?? null) && $this->changedSince($item, $data['updated_at'])) {
            return response()->json([
                'message' => __('Someone changed this BOQ item after you opened it. Check it and save again.'),
                'code' => 'changed',
                'data' => $this->present($item),
            ], 409);
        }

        $this->boq->update($item, $data, $data['reason'], 'app_edit');

        return response()->json(['data' => $this->present($item)]);
    }

    /**
     * DELETE /boq-items/{item}, with a `reason`. The company owner only. A line that is already gone answers as
     * removed, so a retry after a lost reply is not an error.
     */
    public function destroy(Request $request, BoqItem $item): JsonResponse
    {
        abort_unless($this->boq->mayDeleteFromApp($request->user()), 403, __('Only the company owner can delete a BOQ item.'));

        if (! $item->trashed()) {
            $data = $request->validate($this->boq->reasonRules());
            $this->boq->delete($item, $data['reason'], 'app_remove');
        }

        return response()->json(['data' => ['id' => $item->id, 'deleted' => true]]);
    }

    /** Compared to the second: that is all the app is sent. */
    private function changedSince(BoqItem $item, string $openedAt): bool
    {
        return $item->updated_at !== null
            && $item->updated_at->getTimestamp() !== CarbonImmutable::parse($openedAt)->getTimestamp();
    }

    private function present(BoqItem $item): array
    {
        return BoqItemPresenter::present(BoqItem::withLedger()->with('section')->findOrFail($item->id));
    }
}
