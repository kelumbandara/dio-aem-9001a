<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Billing;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices, private readonly PaymentService $payments) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $list = Invoice::query()->with('customer')
            ->when(in_array($status, ['unpaid', 'paid', 'void'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('invoices.index', ['invoices' => $list, 'status' => $status, 'today' => Billing::today()]);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load('customer', 'payments.enteredBy');

        return view('invoices.show', ['invoice' => $invoice, 'balance' => $invoice->balance(), 'paid' => $invoice->paidAmount(), 'today' => Billing::today()]);
    }

    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'received_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'reference' => 'nullable|string|max:255',
        ]);

        try {
            $this->payments->record($invoice, Payment::BANK, $data['amount'], Billing::day($data['received_on']), $data['reference'] ?? null, null, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Payment recorded.');
    }

    public function reverse(Request $request, Invoice $invoice, Payment $payment): RedirectResponse
    {
        abort_unless($payment->invoice_id === $invoice->id, 404);
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        try {
            $this->payments->reverse($payment, $reason, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Payment reversed. A licence already sent stays valid until its last day; use Suspend on the customer if it must stop sooner.');
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:255'])['reason'];

        try {
            $this->invoices->void($invoice, $reason, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Invoice voided. That period is waived and does not hold back the customer\'s licence.');
    }

    /** The printable tax invoice, for staff (the customer sees the same page at /i/{token}). */
    public function print(Invoice $invoice): View
    {
        return view('invoices.print', ['invoice' => $invoice->load('customer')]);
    }
}
