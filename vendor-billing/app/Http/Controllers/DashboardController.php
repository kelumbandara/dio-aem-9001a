<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\IssuedLicence;
use App\Models\Subscription;
use App\Services\LicenceIssuer;
use App\Support\Billing;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(LicenceIssuer $licences): View
    {
        $today = Billing::today();

        $active = Subscription::query()->where('status', 'active')->get();
        $recurring = '0';
        foreach ($active as $subscription) {
            $recurring = Decimal::add($recurring, (string) $subscription->monthly_price);
        }

        $overdue = Invoice::query()->where('status', 'unpaid')->whereDate('due_date', '<', $today->toDateString())->with('customer')->orderBy('due_date')->get();
        $overdueTotal = '0';
        foreach ($overdue as $invoice) {
            $overdueTotal = Decimal::add($overdueTotal, $invoice->balance());
        }

        return view('dashboard', [
            'customers' => Customer::query()->count(),
            'inTrial' => $active->filter(fn (Subscription $s) => $s->trial_ends_on->toDateString() >= $today->toDateString())->count(),
            'recurring' => $recurring,
            'overdue' => $overdue, 'overdueTotal' => $overdueTotal, 'today' => $today,
            'trialsEnding' => Subscription::query()->where('status', 'active')->whereBetween('trial_ends_on', [$today->toDateString(), $today->addDays(14)->toDateString()])->with('customer')->orderBy('trial_ends_on')->get(),
            'needsInstallation' => Customer::query()->whereNull('installation_id')->count(),
            'paidWithoutLicence' => $this->paidWithoutLicence($licences),
            'recent' => IssuedLicence::query()->with('customer')->orderByDesc('id')->limit(5)->get(),
        ]);
    }

    /**
     * Customers who have paid but whose licence could not be issued (no installation ID yet, or no signing key).
     *
     * @return list<array{customer: Customer, through: CarbonImmutable}>
     */
    private function paidWithoutLicence(LicenceIssuer $licences): array
    {
        $out = [];
        foreach (Customer::query()->with('subscription')->get() as $customer) {
            $subscription = $customer->subscription;
            if ($subscription === null || $subscription->status !== 'active') {
                continue;
            }
            $through = $licences->paidThrough($subscription);
            if ($through === null) {
                continue;
            }
            $last = $customer->licences()->where('kind', IssuedLicence::RENEWAL)->orderByDesc('valid_until')->first();
            if ($last === null || $last->valid_until->toDateString() < $through->toDateString()) {
                $out[] = ['customer' => $customer, 'through' => $through];
            }
        }

        return $out;
    }
}
