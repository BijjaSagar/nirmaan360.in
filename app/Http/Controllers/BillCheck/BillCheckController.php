<?php

namespace App\Http\Controllers\BillCheck;

use App\Http\Controllers\Controller;
use App\Models\BillCheck;
use App\Models\RaBill;
use App\Services\BillCheck\BillChecks;
use App\Services\BillCheck\CheckPresenter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillCheckController extends Controller
{
    public function __construct(private BillChecks $checks, private CheckPresenter $presenter) {}

    /** Run the pre-check on a bill and keep what it found. */
    public function store(Request $request, RaBill $bill): JsonResponse|RedirectResponse
    {
        [, $isNew] = $this->checks->check($bill, $request->user());

        if ($request->expectsJson()) {
            return response()->json($this->presenter->present($bill, $request->user()), $isNew ? 201 : 200);
        }

        return back()->with('success', $isNew
            ? __('Bill checked. The findings are listed under Bill pre-check.')
            : __('Checked again. Nothing has changed since the last check.'));
    }

    /** The newest check of a bill, for the card. Money figures are null for a reader who may not see money. */
    public function show(Request $request, RaBill $bill): JsonResponse
    {
        return response()->json($this->presenter->present($bill, $request->user()));
    }

    public function acknowledge(Request $request, BillCheck $check): JsonResponse|RedirectResponse
    {
        $data = $request->validate($this->checks->ackRules(), [
            'remark.min' => __('Write a remark of at least :min characters.'),
        ]);

        $user = $request->user();
        $ack = $this->checks->acknowledge($check, $data['flag_key'], $data['remark'], $user);

        if ($request->expectsJson()) {
            // A remark is free text and may carry amounts, so someone else's goes only to a reader who may see money, as on the card.
            $readsRemark = Money::visible($user) || (int) $ack->user_id === (int) $user->id;

            return response()->json([
                'flag_key' => $ack->flag_key,
                'remark' => $readsRemark ? $ack->remark : null,
                'user_id' => $ack->user_id,
                'created_at' => $ack->created_at?->toIso8601String(),
            ], $ack->wasRecentlyCreated ? 201 : 200);
        }

        return back()->with('success', __('Finding acknowledged.'));
    }

    /** The company's choice: must every finding be acknowledged before a bill is submitted? */
    public function policy(Request $request): JsonResponse|RedirectResponse
    {
        $required = $request->validate(['required' => ['required', 'boolean']])['required'];
        $policy = $this->checks->setRequired($request->user()->organization_id, (bool) $required, $request->user());

        if ($request->expectsJson()) {
            return response()->json(['required' => $policy->required]);
        }

        return back()->with('success', $policy->required
            ? __('From now on a bill can be submitted only after every finding of its check is acknowledged.')
            : __('The check is no longer required before a bill is submitted.'));
    }
}
