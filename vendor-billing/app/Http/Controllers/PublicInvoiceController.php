<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Activity;
use App\Services\PayHere;
use App\Services\PaymentService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** What the customer opens from their e-mail: the invoice and the PayHere payment page, found by an unguessable token. */
class PublicInvoiceController extends Controller
{
    public function __construct(private readonly PayHere $payhere, private readonly PaymentService $payments, private readonly Activity $activity) {}

    public function invoice(string $token): View
    {
        return view('invoices.print', ['invoice' => $this->find($token)->load('customer'), 'payUrl' => route('pay.show', $token)]);
    }

    public function show(string $token): View
    {
        $invoice = $this->find($token)->load('customer');

        return view('pay.show', [
            'invoice' => $invoice, 'balance' => $invoice->balance(), 'payhereReady' => $this->payhere->configured(),
            'url' => $this->payhere->checkoutUrl(),
            'fields' => $invoice->status === 'unpaid' && $this->payhere->configured() && Decimal::compare($invoice->balance(), '0') > 0
                ? $this->payhere->fields($invoice, route('pay.return', $token), route('pay.show', $token), route('payhere.notify'))
                : null,
        ]);
    }

    public function returned(string $token): View
    {
        return view('pay.returned', ['invoice' => $this->find($token)]);
    }

    /** PayHere's server-to-server message. Only a correctly signed message is believed. */
    public function notify(Request $request): Response
    {
        $post = $request->post();
        if (! $this->payhere->verifyNotification($post)) {
            $this->activity->log('payhere.rejected', null, ['order_id' => (string) ($post['order_id'] ?? ''), 'ip' => $request->ip()]);

            return response('invalid', 400);
        }

        $invoice = Invoice::query()->whereRaw("replace(number, '/', '-') = ?", [(string) $post['order_id']])->first();
        $paymentId = (string) ($post['payment_id'] ?? '');
        if ($invoice === null || $paymentId === '') {
            $this->activity->log('payhere.unmatched', null, ['order_id' => (string) $post['order_id'], 'payment_id' => $paymentId]);

            return response('ok');
        }

        // 2 = success. Everything else (pending 0, cancelled -1, failed -2, charged back -3) is only logged.
        if ((string) $post['status_code'] !== '2') {
            $this->activity->log('payhere.not_paid', $invoice, ['status_code' => (string) $post['status_code'], 'payment_id' => $paymentId]);

            return response('ok');
        }
        $amount = (string) $post['payhere_amount'];
        if ($post['payhere_currency'] !== 'LKR' || ! Decimal::isDecimal($amount, 2) || Decimal::compare($amount, '0') <= 0) {
            $this->activity->log('payhere.bad_amount', $invoice, ['amount' => $amount, 'currency' => (string) $post['payhere_currency'], 'payment_id' => $paymentId]);

            return response('ok');
        }

        try {
            // The amount in a correctly signed message is the money PayHere took, so it is recorded as it is.
            $this->payments->record($invoice, Payment::PAYHERE, $amount, CarbonImmutable::now(config('billing.timezone'))->startOfDay(), $paymentId,
                ['method' => (string) ($post['method'] ?? ''), 'card_no' => (string) ($post['card_no'] ?? ''), 'status_message' => (string) ($post['status_message'] ?? '')], null, $paymentId);
        } catch (\DomainException $e) {
            // e.g. the invoice was voided meanwhile: the money arrived, so staff must see it.
            $this->activity->log('payhere.needs_attention', $invoice, ['amount' => $amount, 'payment_id' => $paymentId, 'reason' => $e->getMessage()]);
        }

        return response('ok');
    }

    private function find(string $token): Invoice
    {
        return Invoice::query()->where('public_token', $token)->firstOrFail();
    }
}
