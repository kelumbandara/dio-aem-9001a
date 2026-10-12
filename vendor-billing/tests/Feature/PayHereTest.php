<?php

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\IssuedLicence;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\PayHere;

beforeEach(function () {
    config(['billing.payhere.merchant_id' => '1210001', 'billing.payhere.merchant_secret' => 'unit-test-secret', 'billing.payhere.sandbox' => true]);
});

/** A PayHere notification as its server would send it, signed with the merchant secret. */
function notification(Invoice $invoice, array $over = []): array
{
    $p = array_merge([
        'merchant_id' => '1210001', 'order_id' => $invoice->payhereOrderId(), 'payment_id' => 'PH-1001', 'payhere_amount' => (string) $invoice->total,
        'payhere_currency' => 'LKR', 'status_code' => '2', 'status_message' => 'Successfully received the payment', 'method' => 'VISA', 'card_no' => '************1234',
    ], $over);
    $p['md5sig'] = $over['md5sig'] ?? strtoupper(md5($p['merchant_id'].$p['order_id'].$p['payhere_amount'].$p['payhere_currency'].$p['status_code'].strtoupper(md5('unit-test-secret'))));

    return $p;
}

it('pays the invoice and sends the licence when PayHere confirms a payment', function () {
    useKeys();
    $invoice = firstInvoice(newCustomer());

    $this->post('/payhere/notify', notification($invoice))->assertOk();

    expect($invoice->fresh()->status)->toBe('paid')->and(IssuedLicence::count())->toBe(1);
    $payment = Payment::sole();
    expect($payment->method)->toBe('payhere')->and($payment->payhere_payment_id)->toBe('PH-1001')->and((string) $payment->amount)->toBe('11800.00')->and($payment->entered_by)->toBeNull();
});

it('rejects a notification with a wrong, missing or tampered signature and records nothing', function () {
    $invoice = firstInvoice(newCustomer());

    $this->post('/payhere/notify', notification($invoice, ['md5sig' => str_repeat('A', 32)]))->assertStatus(400);
    $tampered = notification($invoice);
    $tampered['payhere_amount'] = '1.00';                      // the amount changed after signing
    $this->post('/payhere/notify', $tampered)->assertStatus(400);
    $noSig = notification($invoice);
    unset($noSig['md5sig']);
    $this->post('/payhere/notify', $noSig)->assertStatus(400);
    $other = notification($invoice);
    $other['merchant_id'] = '9999999';
    $this->post('/payhere/notify', $other)->assertStatus(400);

    expect(Payment::count())->toBe(0)->and($invoice->fresh()->status)->toBe('unpaid')->and(ActivityLog::where('action', 'payhere.rejected')->count())->toBe(4);
});

it('is idempotent: the same payment id arriving twice is recorded once', function () {
    useKeys();
    $invoice = firstInvoice(newCustomer());
    $n = notification($invoice);

    $this->post('/payhere/notify', $n)->assertOk();
    $this->post('/payhere/notify', $n)->assertOk();

    expect(Payment::count())->toBe(1)->and(IssuedLicence::count())->toBe(1);
});

it('does not mark anything paid for pending, cancelled, failed or charged-back statuses', function () {
    $invoice = firstInvoice(newCustomer());
    foreach (['0', '-1', '-2', '-3'] as $i => $code) {
        $this->post('/payhere/notify', notification($invoice, ['status_code' => $code, 'payment_id' => "PH-{$i}"]))->assertOk();
    }
    expect(Payment::count())->toBe(0)->and($invoice->fresh()->status)->toBe('unpaid')->and(ActivityLog::where('action', 'payhere.not_paid')->count())->toBe(4);
});

it('does not accept a payment in another currency', function () {
    $invoice = firstInvoice(newCustomer());
    $this->post('/payhere/notify', notification($invoice, ['payhere_currency' => 'USD']))->assertOk();
    expect(Payment::count())->toBe(0)->and(ActivityLog::where('action', 'payhere.bad_amount')->count())->toBe(1);
});

it('records the real amount PayHere took even when it is less than the total, leaving the invoice unpaid', function () {
    $invoice = firstInvoice(newCustomer());
    $this->post('/payhere/notify', notification($invoice, ['payhere_amount' => '5000.00']))->assertOk();

    expect((string) Payment::sole()->amount)->toBe('5000.00')->and($invoice->fresh()->status)->toBe('unpaid')->and($invoice->fresh()->balance())->toBe('6800.00');
});

it('flags a payment for staff when the invoice was voided meanwhile, instead of dropping it silently', function () {
    $invoice = firstInvoice(newCustomer());
    app(InvoiceService::class)->void($invoice, 'mistake');

    $this->post('/payhere/notify', notification($invoice))->assertOk();

    expect(Payment::count())->toBe(0)->and(ActivityLog::where('action', 'payhere.needs_attention')->count())->toBe(1);
});

it('answers ok but records nothing for an unknown order', function () {
    $invoice = firstInvoice(newCustomer());
    $this->post('/payhere/notify', notification($invoice, ['order_id' => 'SUB-2099-00-99999']))->assertOk();
    expect(Payment::count())->toBe(0)->and(ActivityLog::where('action', 'payhere.unmatched')->count())->toBe(1);
});

it('builds the checkout form with the documented hash and the outstanding balance', function () {
    $invoice = firstInvoice(newCustomer());
    $invoice->customer->update(['contact_name' => 'Nimal Perera', 'phone' => '0771234567']);

    $page = $this->get('/pay/'.$invoice->public_token)->assertOk()->assertSee('sandbox.payhere.lk/pay/checkout', false);
    $fields = app(PayHere::class)->fields($invoice->fresh(), 'https://b.test/r', 'https://b.test/c', 'https://b.test/n');

    $expected = strtoupper(md5('1210001'.'SUB-2026-27-00001'.'11800.00'.'LKR'.strtoupper(md5('unit-test-secret'))));
    expect($fields['hash'])->toBe($expected)->and($fields['amount'])->toBe('11800.00')->and($fields['order_id'])->toBe('SUB-2026-27-00001')
        ->and($fields['first_name'])->toBe('Nimal')->and($fields['last_name'])->toBe('Perera')->and($fields['currency'])->toBe('LKR');
    $page->assertSee($expected, false);
    $page->assertDontSee('unit-test-secret', false);          // the merchant secret never reaches the page
});

it('shows a paid invoice as paid and offers no payment form', function () {
    useKeys();
    $invoice = firstInvoice(newCustomer());
    $this->post('/payhere/notify', notification($invoice));

    $this->get('/pay/'.$invoice->public_token)->assertOk()->assertSee('This invoice is paid')->assertDontSee('Pay online with PayHere');
    $this->get('/pay/'.$invoice->public_token.'/thanks')->assertOk()->assertSee('Payment received');
});

it('says online payment is unavailable when PayHere is not configured', function () {
    config(['billing.payhere.merchant_id' => null]);
    $invoice = firstInvoice(newCustomer());
    $this->get('/pay/'.$invoice->public_token)->assertOk()->assertSee('not available')->assertDontSee('Pay online with PayHere');
});

it('shows the public tax invoice only to someone with the unguessable link', function () {
    $invoice = firstInvoice(newCustomer());
    $this->get('/i/'.$invoice->public_token)->assertOk()->assertSee('TAX INVOICE')->assertSee('SUB/2026-27/00001')->assertSee('11,800.00')->assertSee('123456789-7000');
    $this->get('/i/'.str_repeat('x', 40))->assertNotFound();
    $this->get('/pay/'.str_repeat('x', 40))->assertNotFound();
});
