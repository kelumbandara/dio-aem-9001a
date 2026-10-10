# Approval Engine

Reusable, backend-enforced. UI buttons are convenience only; services refuse to execute a
gated action without an approved, unexpired, unconsumed request.

## 1. Concepts

- **Subject**: what is approved (polymorphic: PO, requisition, sales discount, journal, stock
  adjustment, AI-proposed action, outbound message).
- **Policy**: selects chain by subject type + conditions (amount, branch, department, risk).
- **Authority matrix**: role/user × subject type × max amount × allowed action.
- **Request**: immutable payload snapshot + `payload_hash`, requester, channel, optional `ai_run_id`.
- **Steps**: ordered or parallel approvers; each step = role or user, with delegation.
- **Decision**: approve/reject + mandatory comment on reject.
- **Execution**: after final approval the service **revalidates** (permissions, period open, stock,
  limits, payload hash unchanged, not expired) then executes once (idempotent), recording
  `approval_executions`.

## 2. State machine

`draft → pending → (approved | rejected | expired | cancelled) → executed | execution_failed`

Rules: requester cannot approve own request (configurable exception for owner); an approver
cannot approve above their authority; delegation is time-boxed and audited; expiry job runs
hourly; reminders via notifications (and WhatsApp when enabled and consented).

## 3. Enforcement pattern

```php
$decision = $approvals->gate($action, $subject, $payload, $actor);
if ($decision->requiresApproval()) return $decision->request();   // 202
return $action->execute();                                        // not gated
// later: ApprovalApproved listener -> ExecuteApprovedAction (revalidate + execute once)
```

Every gated Action implements `Approvable` (`approvalKey()`, `payloadFor()`, `revalidate()`,
`execute()`); there is no code path to `execute()` without the gate. Architecture test checks it.

## 4. Default gated operations (configurable per customer)

Purchase requisition/PO over thresholds · sales discount over % · credit limit override ·
stock adjustment above value · journal posting manual/back-dated · period reopen · supplier
payment · bank detail change · price list change · write-off · all AI R3 actions · external
bulk messages · user/role changes (MFA step-up, not AI).

## 5. Audit

All transitions → `audit_logs` + `approval_decisions`. Approver identity, time, IP/channel,
comment, and the exact payload hash are retained.
