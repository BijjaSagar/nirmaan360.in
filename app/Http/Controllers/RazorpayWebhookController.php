<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Services\BillingService;
use App\Services\RazorpayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Activates a plan even if the customer closes the browser after paying. */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, RazorpayGateway $gateway, BillingService $billing): JsonResponse
    {
        abort_unless($gateway->webhookSignatureIsValid($request->getContent(), (string) $request->header('X-Razorpay-Signature')), 400, 'Invalid signature.');

        if (! in_array($request->input('event'), ['payment.captured', 'order.paid'], true)) {
            return response()->json(['status' => 'ignored']);
        }

        $entity = (array) $request->input('payload.payment.entity');
        $payment = BillingPayment::withoutGlobalScopes()->where('gateway_order_id', $entity['order_id'] ?? '')->first();

        if (! $payment) {
            return response()->json(['status' => 'ignored']);
        }

        if ((string) ($entity['amount'] ?? '') !== $payment->paise()) {
            Log::warning('Razorpay webhook amount mismatch', ['payment' => $payment->id, 'amount' => $entity['amount'] ?? null]);

            return response()->json(['status' => 'amount_mismatch'], 422);
        }

        $billing->markPaid($payment, (string) $entity['id']);

        return response()->json(['status' => 'ok']);
    }
}
