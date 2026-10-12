<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing;
use App\Support\Decimal;
use App\Support\LicenceException;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Money received. Bank transfers are keyed in by staff; PayHere payments arrive through its signed notification. When the
 * payments on an invoice reach its total the invoice is paid and the licence for the period is signed and sent.
 */
final class PaymentService
{
    public function __construct(private readonly LicenceIssuer $licences, private readonly Activity $activity) {}

    /**
     * Record a payment. Idempotent for PayHere (the same payment id is recorded once).
     *
     * @param  array<string, mixed>|null  $details
     */
    public function record(Invoice $invoice, string $method, string $amount, ?CarbonImmutable $receivedOn = null, ?string $reference = null, ?array $details = null, ?User $by = null, ?string $payhereId = null): Payment
    {
        if (! Decimal::isDecimal($amount, 2) || Decimal::compare($amount, '0') <= 0) {
            throw new DomainException('The amount must be more than zero, with at most two decimals.');
        }

        try {
            $payment = DB::transaction(function () use ($invoice, $method, $amount, $receivedOn, $reference, $details, $by, $payhereId): Payment {
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($payhereId !== null && ($existing = Payment::query()->where('payhere_payment_id', $payhereId)->first()) !== null) {
                    return $existing;
                }
                if ($locked->status === 'void') {
                    throw new DomainException('This invoice is void; no payment can be recorded against it.');
                }

                $payment = $locked->payments()->create([
                    'method' => $method, 'amount' => Decimal::round($amount, 2), 'received_on' => ($receivedOn ?? Billing::today())->toDateString(),
                    'reference' => $reference, 'payhere_payment_id' => $payhereId, 'details' => $details, 'entered_by' => $by?->id,
                ]);

                $balance = $locked->balance();
                $this->activity->log('payment.recorded', $payment, [
                    'invoice' => $locked->number, 'method' => $method, 'amount' => $payment->amount,
                    'overpaid' => Decimal::compare(Decimal::sub($locked->paidAmount(), (string) $locked->total), '0') > 0,
                ], $by?->id);
                if ($locked->status === 'unpaid' && Decimal::compare($balance, '0') === 0) {
                    $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
                    $this->activity->log('invoice.paid', $locked, ['number' => $locked->number], $by?->id);
                }

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two PayHere notifications for the same payment arrived at once: the other one won.
            $existing = $payhereId === null ? null : Payment::query()->where('payhere_payment_id', $payhereId)->first();
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        $this->issueLicenceIfPaid($invoice->refresh());

        return $payment;
    }

    /** Take a payment back (wrong amount, bounced transfer). The invoice becomes unpaid again; a licence already sent stays valid until its last day. */
    public function reverse(Payment $payment, string $reason, ?User $by = null): void
    {
        DB::transaction(function () use ($payment, $reason, $by): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->reversed_at !== null) {
                throw new DomainException('This payment has already been reversed.');
            }
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['reversed_at' => now(), 'reversal_reason' => $reason, 'reversed_by' => $by?->id])->save();

            if ($invoice->status === 'paid' && Decimal::compare($invoice->balance(), '0') > 0) {
                $invoice->forceFill(['status' => 'unpaid', 'paid_at' => null])->save();
            }
            $this->activity->log('payment.reversed', $locked, ['invoice' => $invoice->number, 'amount' => $locked->amount, 'reason' => $reason], $by?->id);
            $payment->refresh();
        });
    }

    /** Sign and send the licence now covered by paid invoices. A missing key does not undo the payment: staff can issue it later. */
    public function issueLicenceIfPaid(Invoice $invoice): void
    {
        if ($invoice->status !== 'paid') {
            return;
        }
        try {
            $this->licences->issueCoverage($invoice->customer()->firstOrFail());
        } catch (LicenceException $e) {
            $this->activity->log('licence.failed', $invoice, ['number' => $invoice->number, 'error' => $e->getMessage()]);
        }
    }
}
