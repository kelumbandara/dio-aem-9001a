<?php

namespace App\Console\Commands;

use App\Mail\ReminderMail;
use App\Models\Invoice;
use App\Services\Activity;
use App\Support\Billing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BillingRemind extends Command
{
    protected $signature = 'billing:remind';

    protected $description = 'E-mail customers about unpaid invoices (before the due date, on it, and after; once per stage)';

    public function handle(Activity $activity): int
    {
        $today = Billing::today();
        $stages = config('billing.reminder_days');
        sort($stages);
        $sent = 0;

        $invoices = Invoice::query()->where('status', 'unpaid')->whereHas('subscription', fn ($q) => $q->where('status', 'active'))->with('customer')->get();
        foreach ($invoices as $invoice) {
            $days = (int) Billing::day($invoice->due_date->toDateString())->diffInDays($today, false);
            $reached = array_values(array_filter($stages, fn (int $s) => $s <= $days));
            $already = $invoice->reminders_sent ?? [];
            if ($reached === [] || in_array(max($reached), $already, true)) {
                continue;
            }

            try {
                Mail::to($invoice->customer->email)->send(new ReminderMail($invoice, $days));
            } catch (Throwable $e) {
                $activity->log('reminder.failed', $invoice, ['error' => $e->getMessage()]);

                continue;
            }
            // Earlier stages count as sent too, so a long-overdue invoice gets one message, not five.
            $invoice->forceFill(['reminders_sent' => array_values(array_unique(array_merge($already, $reached)))])->save();
            $activity->log('reminder.sent', $invoice, ['number' => $invoice->number, 'days_from_due' => $days]);
            $sent++;
        }
        $this->components->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
