<?php

namespace App\Modules\Approvals\Http\Controllers\Web;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\Demo\DemoExpense;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Development demo (local environment only): request approval for a made-up expense. */
class DemoController extends Controller
{
    public function create(): View
    {
        return view('approvals::demo', ['token' => (string) Str::uuid()]);
    }

    public function store(Request $request, ApprovalService $approvals, DemoExpense $expense): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'token' => ['required', 'uuid'],
        ]);
        $payload = ['description' => $data['description'], 'amount' => $data['amount']];

        try {
            $decision = $approvals->gate(DemoExpense::KEY, $payload, $request->user()->id, idempotencyKey: $data['token']);
        } catch (BusinessException $e) {
            return back()->withInput()->withErrors(['demo' => $e->getMessage()]);
        }

        if (! $decision->requiresApproval) {
            $expense->release($payload);

            return redirect()->route('approvals.demo')->with('status', 'No approval was needed for this amount, so it was released straight away.');
        }

        $approval = ApprovalRequest::query()->where('public_id', $decision->requestId)->firstOrFail();

        return redirect()->route('approvals.show', $approval)->with('status', "Approval {$decision->documentNo} was requested.");
    }
}
