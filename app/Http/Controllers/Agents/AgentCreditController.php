<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentCreditLedger;
use App\Models\AgentCreditTopup;
use App\Services\Agents\CostMeter;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Services\RazorpayGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class AgentCreditController extends Controller
{
    public function __construct(private CreditService $credits, private RazorpayGateway $razorpay, private ModelGateway $gateway) {}

    public function index(Request $request, CostMeter $meter): View
    {
        $organization = $request->user()->organization;

        return view('modules.agents.credits.index', [
            'wallet' => $this->credits->wallet($organization),
            'packs' => collect(config('modules.agents.packs'))->map(fn ($pack, $key) => [...$this->credits->quote($key), 'key' => $key]),
            'canBuy' => $this->razorpay->isLive() && $this->gateway->isConnected(),
            'paymentsLive' => $this->razorpay->isLive(),
            'ledger' => AgentCreditLedger::latest('id')->take(20)->get(),
            'usage' => $meter->usage($organization, now('Asia/Kolkata')->startOfMonth()),
        ]);
    }

    public function checkout(Request $request): View|RedirectResponse
    {
        $data = $request->validate(['pack' => ['required', Rule::in(array_keys(config('modules.agents.packs')))]]);

        if (! $this->gateway->isConnected()) {
            return back()->withErrors(['pack' => __('AI agents are not connected. No run can start until a platform admin saves an API key.')]);
        }

        if (! $this->razorpay->isLive()) {
            return back()->withErrors(['pack' => __('Online payment is not switched on yet.')]);
        }

        try {
            $topup = $this->credits->startTopup($request->user()->organization, $request->user(), $data['pack']);
        } catch (RuntimeException $exception) {
            Log::error('Credit checkout could not start', ['error' => $exception->getMessage()]);

            return back()->withErrors(['pack' => __('We could not reach the payment service. Please try again.')]);
        }

        return view('modules.agents.credits.checkout', ['topup' => $topup, 'keyId' => $this->razorpay->keyId()]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ]);

        $topup = AgentCreditTopup::where('gateway_order_id', $data['razorpay_order_id'])->firstOrFail();
        $this->credits->confirmCheckout($topup, $data['razorpay_payment_id'], $data['razorpay_signature']);

        return redirect()->route('agent-credits.index')->with('success', __('Payment received. Credits added.'));
    }
}
