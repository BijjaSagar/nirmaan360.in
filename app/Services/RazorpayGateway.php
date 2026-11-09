<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Razorpay Orders API and signature checks. Online payment stays off until keys are set. */
class RazorpayGateway
{
    public function isLive(): bool
    {
        return filled($this->keyId()) && filled($this->keySecret());
    }

    public function keyId(): ?string
    {
        return config('services.razorpay.key_id');
    }

    private function keySecret(): ?string
    {
        return config('services.razorpay.key_secret');
    }

    /** @return array{id:string} */
    public function createOrder(string $paise, string $receipt, array $notes = []): array
    {
        if (! $this->isLive()) {
            throw new RuntimeException('Razorpay keys are not configured.');
        }

        $response = Http::withBasicAuth($this->keyId(), $this->keySecret())
            ->timeout(15)
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => (int) $paise,
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => $notes,
            ]);

        if (! $response->successful() || blank($response->json('id'))) {
            throw new RuntimeException('Razorpay could not create the order: HTTP '.$response->status());
        }

        return $response->json();
    }

    public function checkoutSignatureIsValid(string $orderId, string $paymentId, string $signature): bool
    {
        return filled($this->keySecret())
            && hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $this->keySecret()), $signature);
    }

    public function webhookSignatureIsValid(string $body, string $signature): bool
    {
        $secret = config('services.razorpay.webhook_secret');

        return filled($secret) && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }
}
