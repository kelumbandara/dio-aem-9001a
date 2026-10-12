<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Decimal;

/**
 * PayHere checkout and notification. Only the merchant secret (environment) signs and checks messages; a notification is
 * trusted only when its md5sig matches, because anyone can post to the notify address.
 */
final class PayHere
{
    public function configured(): bool
    {
        return filled(config('billing.payhere.merchant_id')) && filled(config('billing.payhere.merchant_secret'));
    }

    public function checkoutUrl(): string
    {
        return config('billing.payhere.sandbox') ? 'https://sandbox.payhere.lk/pay/checkout' : 'https://www.payhere.lk/pay/checkout';
    }

    /** @return array<string, string> the hidden form fields for one invoice (the outstanding balance, in LKR) */
    public function fields(Invoice $invoice, string $returnUrl, string $cancelUrl, string $notifyUrl): array
    {
        $customer = $invoice->customer;
        $amount = Decimal::round($invoice->balance(), 2);
        $merchantId = (string) config('billing.payhere.merchant_id');
        $parts = preg_split('/\s+/', trim((string) $customer->contact_name), 2) ?: [];

        return [
            'merchant_id' => $merchantId, 'return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'notify_url' => $notifyUrl,
            'order_id' => $invoice->payhereOrderId(), 'items' => 'Subscription '.$invoice->number, 'currency' => 'LKR', 'amount' => $amount,
            'first_name' => $parts[0] !== '' ? $parts[0] : $customer->name, 'last_name' => $parts[1] ?? '-', 'email' => $customer->email,
            'phone' => (string) ($customer->phone ?: '0000000000'), 'address' => (string) ($customer->address ?: '-'), 'city' => '-', 'country' => 'Sri Lanka',
            'hash' => strtoupper(md5($merchantId.$invoice->payhereOrderId().$amount.'LKR'.$this->secretHash())),
        ];
    }

    /** @param  array<string, mixed>  $post */
    public function verifyNotification(array $post): bool
    {
        foreach (['merchant_id', 'order_id', 'payhere_amount', 'payhere_currency', 'status_code', 'md5sig'] as $field) {
            if (! isset($post[$field]) || ! is_scalar($post[$field])) {
                return false;
            }
        }
        if (! $this->configured() || (string) $post['merchant_id'] !== (string) config('billing.payhere.merchant_id')) {
            return false;
        }
        $expected = strtoupper(md5($post['merchant_id'].$post['order_id'].$post['payhere_amount'].$post['payhere_currency'].$post['status_code'].$this->secretHash()));

        return hash_equals($expected, strtoupper((string) $post['md5sig']));
    }

    private function secretHash(): string
    {
        return strtoupper(md5((string) config('billing.payhere.merchant_secret')));
    }
}
