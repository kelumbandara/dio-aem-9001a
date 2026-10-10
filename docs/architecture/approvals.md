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

## 6. As implemented (Sprint 2)

Module `Approvals` (depends on `Core` only). How to use it from a module: [../development/gating-an-action.md](../development/gating-an-action.md).

### State machine

`pending → approved → executed`, with side exits `rejected`, `expired`, `cancelled` and
`approved → execution_failed → approved` (retry). `draft` is not a request state: a draft is the
originating document's own status; a request only exists once approval is actually needed.
Every transition runs in one transaction under a row lock on the request and writes audit, outbox event
and notifications in that same transaction.

### Rules enforced by the engine (never by the UI)

| Rule | Where |
|---|---|
| Only a person holding a step's role (in the request's branch, or without a branch), the named user, or an **active delegate** of such a person can decide it | `ApproverResolver::deciderFor` |
| Roles are read through `UserDirectory`, **not Gates** – `super-admin` and `admin` gain no approval rights from holding every permission | tests: "gives super-admin no shortcut" |
| The requester cannot approve their own request (policy flag `allow_self_approval`, default off) – also when acting as a delegate | same |
| `distinct_approvers` (default on): one person cannot satisfy two steps of one request | same |
| Authority matrix: if an action has **any** matrix row, only listed roles/users may approve, up to `max_amount` (inclusive; empty = unlimited). A delegate is judged by the **delegator's** limit. An action with no rows is unrestricted | `authorityAllows` |
| Sequential and parallel steps: steps with the same `step_order` are parallel; the next order opens (and is notified) when all of the current one approved | `afterApproval` |
| A policy nobody could satisfy (e.g. the only approver is the requester) is refused at request time with `approvals.no_approver`; nothing is created and no document number is burned | `gate` |
| Rejecting needs a reason (≥ 3 characters); any rejection ends the request and skips remaining steps | `reject` |
| The policy is **snapshotted** into the request (conditions, flags, steps), so editing or deleting a policy never changes requests already made | `policy_snapshot` |
| Amounts are decimal **strings**; payloads containing floats are refused | `PayloadHasher`, `gate` |

### Execution

Approval of the last step dispatches `ExecuteApprovedRequestJob` after commit. `ExecuteApprovedRequest`
then, under a row lock and in one transaction: ignores anything not `approved`; expires it if past the
deadline; **recomputes the payload hash** and refuses on mismatch (`approvals.payload_changed`, audited as
`approval.payload_tampered`); calls `Approvable::revalidate()`, then `execute()` inside a savepoint so a
failing action rolls back its own writes while the failure is still recorded. Outcomes are rows in
`approval_executions` (one per attempt; at most one `succeeded`, also enforced by a partial unique index).
Unexpected exceptions are logged and stored only as a generic message. A failed execution notifies the
requester and users with role `admin`; an approvals manager can retry it (`approvals.request.manage`).

"No path to `execute()` without the gate" is structural: `execute()` requires an `ExecutionToken`, whose
constructor is private and which only `ExecuteApprovedRequest` can issue. An architecture test fails the
build if any other class uses the token or the executor.

### Operations

`approvals:expire` (hourly) expires open requests past `expires_at` (approved-but-not-yet-run requests
included – approval does not live forever); `approvals:remind` (hourly) reminds current approvers after the
policy's `reminder_after_hours`, at most once per period. Both are scheduled by the module.

### Not built yet (still in the design above)

MFA step-up for sensitive approvals; WhatsApp/email reminders (Communications, S14); conditions on
department; branch-scoped policies have no screen (the column and matching work, set via data/API later);
policy and authority-matrix management has screens but no public API; AI-originated requests carry
`channel='ai'` and `ai_run_id` but no AI exists yet (S11).
