<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\LicenceIssuer;
use App\Support\Billing;
use App\Support\LicenceException;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers, private readonly LicenceIssuer $licences) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $list = Customer::query()->with('subscription')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('customers.index', ['customers' => $list, 'q' => $q, 'today' => Billing::today()]);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null) + $request->validate(['started_on' => 'nullable|date_format:Y-m-d']);
        $started = $data['started_on'] ?? null;
        unset($data['started_on']);

        try {
            $customer = $this->customers->create($data, $started ? Billing::day($started) : null, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $customer)->with('status', "Customer {$customer->code} created.");
    }

    public function show(Customer $customer): View
    {
        $customer->load('subscription');

        return view('customers.show', [
            'customer' => $customer, 'today' => Billing::today(),
            'invoices' => $customer->invoices()->orderByDesc('period_start')->get(),
            'licences' => $customer->licences()->orderByDesc('id')->get(),
            'paidThrough' => $customer->subscription ? $this->licences->paidThrough($customer->subscription) : null,
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, $this->validated($request, $customer), $request->user());

        return redirect()->route('customers.show', $customer)->with('status', 'Saved.');
    }

    public function token(Request $request, Customer $customer): RedirectResponse
    {
        $token = $this->customers->rotateApiToken($customer, $request->user());

        return redirect()->route('customers.show', $customer)->with('new_token', $token);
    }

    public function issueLicence(Request $request, Customer $customer): RedirectResponse
    {
        try {
            $licence = $this->licences->issueCoverage($customer);
        } catch (LicenceException $e) {
            return back()->withErrors(['licence' => $e->getMessage()]);
        }

        return back()->with('status', $licence === null ? 'Nothing new to issue: the last licence already covers everything paid (or no installation ID / paid period yet).' : "Licence {$licence->serial} issued.");
    }

    public function suspend(Request $request, Customer $customer): RedirectResponse
    {
        $this->licences->suspend($customer, $request->user());

        return back()->with('status', 'Suspended: no more invoices, and the installation locks as soon as it fetches its licence.');
    }

    public function resume(Request $request, Customer $customer): RedirectResponse
    {
        try {
            $this->licences->resume($customer, $request->user());
        } catch (LicenceException $e) {
            return back()->withErrors(['licence' => $e->getMessage()]);
        }

        return back()->with('status', 'Resumed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Customer $customer): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:1000',
            'vat_number' => 'nullable|string|max:40',
            'installation_id' => ['nullable', 'string', 'max:100', Rule::unique('customers', 'installation_id')->ignore($customer?->id)],
        ]);
    }
}
