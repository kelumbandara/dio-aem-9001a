# Gating an action behind approval

For module developers. Background and rules: [../architecture/approvals.md](../architecture/approvals.md).

An action is *gated* when a policy says it needs approval. You never check policies yourself: you ask
the gate, and either carry on with your normal Action or return the pending request.

## 1. Describe the action once (`Approvable`)

```php
namespace App\Modules\Procurement\Approvals;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Support\Exceptions\BusinessException;

final class ApprovePurchaseOrder implements Approvable
{
    public function __construct(private readonly PostPurchaseOrder $post /* your normal Action */) {}

    public static function approvalKey(): string { return 'procurement.po.approve'; }   // stable; policies refer to it
    public function riskLevel(): string { return 'R3'; }                                 // R1 draft · R2 change · R3 commit

    public function amount(array $payload): ?string { return $payload['total']; }       // decimal STRING in LKR, or null
    public function summary(array $payload): string { return "PO {$payload['po_no']} to {$payload['supplier']}"; }

    public function revalidate(array $payload, int $requestedBy): void
    {
        // Re-check what could have changed since the request: period open, supplier active, limits…
        // Throw a BusinessException to refuse; the request becomes "execution_failed" with your code.
    }

    public function execute(array $payload, ExecutionToken $token): array
    {
        $this->post(poId: $payload['po_id']);       // call the SAME Action the UI uses when no approval is needed
        return ['posted' => true];
    }
}
```

Register it from your module provider (`boot`): `app(ApprovalService::class)->registerAction(ApprovePurchaseOrder::class);`

`execute()` cannot be called without an `ExecutionToken`, and only the approval engine can create one.
It runs in a database transaction that rolls back if it throws, and the engine guarantees it runs at
most once per request.

## 2. Ask the gate where the action would run

```php
$decision = $approvals->gate(
    actionKey: ApprovePurchaseOrder::approvalKey(),
    payload: ['po_id' => $po->id, 'po_no' => $po->document_no, 'supplier' => $po->supplier_name, 'total' => $po->total], // strings, no floats
    requestedBy: $user->id,
    subject: $po,                       // optional: the record concerned
    branchId: $po->branch_id,           // optional: matches branch-scoped policies and approvers
    idempotencyKey: $request->header('Idempotency-Key'),
    channel: 'api',
);

if ($decision->requiresApproval) {
    return response()->json(['data' => ['approval_request_id' => $decision->requestId, 'status' => 'pending']], 202);
}

return $postPurchaseOrder($po);          // no policy matched: run it now
```

- The payload is **snapshotted and hashed**. Put in it everything `execute()` needs; do not read mutable
  state later and assume it is what was approved.
- A repeated `idempotencyKey` returns the same request instead of creating another.
- If no active person could approve (for instance the only holder of the approver role is the requester),
  `gate()` throws `approvals.no_approver`. Show that message; do not swallow it.

## 3. Let people configure it

Admins create the policy in *Approval policies* (action key, amount range, risk levels, ordered/parallel
approver steps by role or person, expiry, reminders) and optionally limits per role/person in *Approval
limits*. Until a policy exists the action simply is not gated. Document the action key and its payload
in your module's docs so admins know what they are gating.

## 4. Test it

Use the pattern in `tests/Feature/Approvals`: register your Approvable, create a policy with
`SavePolicy`, call `gate()`, then `ApprovalEngine::approve()`; assert your Action's effect happened
exactly once. `tests/Support/Approvals/FakePayment.php` is a complete minimal example. Your module's
architecture rules still apply: import only `Approvals\Contracts`, `Approvals\DTOs` and Core's public
namespaces.
