<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Monthly tax invoices. Number `SUB/2026-27/00001`: per financial year (1 April – 31 March), gapless because the counter
 * moves in the same transaction as the invoice. Amounts are fixed at issue: the plan price is a decimal excluding VAT,
 * VAT = price × rate rounded to the cent, total = price + VAT.
 */
final class InvoiceService
{
    public function __construct(private readonly Settings $settings, private readonly Activity $activity) {}

    /** Every invoice that should exist by $today (periods whose invoice date, `lead days` before the period starts, has come). */
    public function generateDue(?CarbonImmutable $today = null): int
    {
        $today ??= Billing::today();
        $lead = (int) $this->settings->get('invoice_lead_days');
        $created = 0;

        $subscriptions = Subscription::query()->where('status', 'active')->get();
        foreach ($subscriptions as $subscription) {
            for ($n = 0; $n < 1200 && $created < 600; $n++) {
                if ($subscription->periodStart($n)->subDays($lead)->greaterThan($today)) {
                    break;
                }
                $invoice = $this->issueForPeriod($subscription, $n, $today);
                if ($invoice !== null) {
                    $created++;
                    $this->email($invoice);
                }
            }
        }

        return $created;
    }

    /** The invoice for period n, or null when it already exists. */
    public function issueForPeriod(Subscription $subscription, int $n, ?CarbonImmutable $today = null): ?Invoice
    {
        $today ??= Billing::today();
        $start = $subscription->periodStart($n);

        return DB::transaction(function () use ($subscription, $n, $start, $today): ?Invoice {
            // Serialise per subscription so two runs cannot both create the same period.
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($locked->invoices()->whereDate('period_start', $start->toDateString())->exists()) {
                return null;
            }

            $customer = $locked->customer;
            $rate = Decimal::round($this->settings->get('vat_rate') === '' ? '0' : $this->settings->get('vat_rate'), 2);
            $net = Decimal::round((string) $locked->monthly_price, 2);
            $vat = Decimal::round(Decimal::div(Decimal::mul($net, $rate), '100'), 2);

            $year = Billing::financialYear($today);
            DB::table('invoice_counters')->insertOrIgnore(['financial_year' => $year, 'last_number' => 0]);
            $counter = DB::table('invoice_counters')->where('financial_year', $year)->lockForUpdate()->first();
            $number = $counter->last_number + 1;
            DB::table('invoice_counters')->where('financial_year', $year)->update(['last_number' => $number]);

            $invoice = Invoice::query()->create([
                'number' => sprintf('SUB/%s/%05d', $year, $number),
                'customer_id' => $customer->id,
                'subscription_id' => $locked->id,
                'period_start' => $start->toDateString(),
                'period_end' => $locked->periodEnd($n)->toDateString(),
                'issue_date' => $today->toDateString(),
                'due_date' => $start->toDateString(),
                'net' => $net, 'vat_rate' => $rate, 'vat' => $vat, 'total' => Decimal::round(Decimal::add($net, $vat), 2),
                'status' => 'unpaid',
                'public_token' => Str::random(40),
                'billed_to' => ['code' => $customer->code, 'name' => $customer->name, 'address' => $customer->address, 'vat_number' => $customer->vat_number, 'email' => $customer->email],
                'issuer' => $this->settings->issuer(),
            ]);
            $this->activity->log('invoice.issued', $invoice, ['number' => $invoice->number, 'total' => $invoice->total]);

            return $invoice;
        });
    }

    private function email(Invoice $invoice): void
    {
        try {
            Mail::to($invoice->billed_to['email'])->send(new InvoiceMail($invoice));
        } catch (Throwable $e) {
            $this->activity->log('invoice.email_failed', $invoice, ['error' => $e->getMessage()]);
        }
    }

    /** Cancel an invoice nobody has paid. A void period is waived: it does not hold back the customer's licence. */
    public function void(Invoice $invoice, string $reason, ?User $by = null): void
    {
        DB::transaction(function () use ($invoice, $reason, $by): void {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'unpaid') {
                throw new DomainException('Only an unpaid invoice can be voided.');
            }
            if ($locked->payments()->whereNull('reversed_at')->exists()) {
                throw new DomainException('This invoice has payments; reverse them first.');
            }
            $locked->forceFill(['status' => 'void', 'void_reason' => $reason])->save();
            $this->activity->log('invoice.voided', $locked, ['number' => $locked->number, 'reason' => $reason], $by?->id);
            $invoice->refresh();
        });
    }
}
