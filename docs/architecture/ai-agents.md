# AI Platform: Agents, Tools, Permissions

## 1. Components (module `AI`)

| Component | Responsibility |
|---|---|
| `AIProviderInterface` | `complete(request): response` incl. tool-calling, streaming, token usage. Implementations: `ClaudeProvider` (primary), `OptionalProviderAdapter` |
| `AIOrchestrator` | The run loop: build context → call provider → handle tool calls → final grounded answer |
| `AgentRegistry` | Agent definitions: system prompt, allowed tools, department, default model |
| `AgentContextBuilder` | Builds minimal, permission-filtered context (user, branch, role, locale) |
| `BusinessToolRegistry` | Registry of tools = thin adapters over module Actions/Queries |
| `ToolAuthorizationService` | Checks the **acting user's** permission + record access for every call |
| `AISafetyPolicyService` | Risk classification, prompt-injection rules, output filters, rate limits |
| `AIApprovalService` | Turns high-risk tool calls into `approval_requests` |
| `AIConversationService` | Conversations/messages persistence, channel binding |
| `AIUsageMeteringService` | Tokens/cost per user/agent/day; budgets and cut-offs |
| `AIRetrievalService` | Structured retrieval (queries) first; document retrieval (RAG) later |
| `AIExecutionAuditService` | Immutable record of prompt hash, tool calls, decisions, results |

Model names are configuration (`config/ai.php` + settings), never hard-coded.

## 2. Run flow

1. Authenticate user (session/token/verified channel identity).
2. Resolve department, branch, role → select agent(s).
3. `AgentContextBuilder` fetches only needed structured data via query tools.
4. Provider call with the agent's allowed tool schemas only.
5. On each tool call: JSON-schema validate → `ToolAuthorizationService` → risk policy.
6. Low risk → execute through the module Action (same code path as UI).
7. High risk → create approval request; tool result tells model "pending approval".
8. Persist everything; return answer with **references** (document ids/links).

Hard rules: no raw SQL, shell, file system or secret access for models. Model text is never an
authorisation signal. Tool results containing user-supplied text are treated as untrusted data
(prompt-injection boundary: delimited, never allowed to change tool permissions or system prompt).
The AI acts with the **requesting user's** permissions (never a super-user).

## 3. Risk levels

| Level | Definition | Handling |
|---|---|---|
| R0 Read | Query data the user may already see | Execute; audit |
| R1 Draft | Creates draft/unposted records, summaries, suggestions | Execute; audit; user reviews in UI |
| R2 Change | Submits/updates non-financial documents, sends internal notifications | Execute if user has permission and under limits, else approval |
| R3 Commit | Posts stock/finance, approves, sends external messages, price/credit changes | **Always approval** (human, per matrix) |
| R4 Forbidden | Delete posted data, change roles/permissions, config, secrets, machine control | Never available to AI |

## 4. Agents and tool permission matrix

Legend: R0 read · R1 draft · R2 change · — none. Permission also requires the user's own role
permission; the matrix is the **maximum** the agent may ever offer.

| Agent | Sales | Purch | Inv | Prod/MES | Quality | Maint | Finance | HR | Cust. comms | Reporting |
|---|---|---|---|---|---|---|---|---|---|---|
| CEOAgent | R0 | R0 | R0 | R0 | R0 | R0 | R0 | R0 | — | R0 (all KPIs) |
| SalesAgent | R0 R1 (quotation, follow-up) | — | R0 | R0 (status) | — | — | R0 (own customers' AR) | — | R1 | Sales |
| PurchasingAgent | — | R0 R1 (requisition/PO draft) | R0 | R0 | — | — | R0 (AP) | — | R1 | Purch |
| InventoryAgent | R0 | R0 | R0 R1 (adjustment/transfer draft) | R0 | — | — | R0 | — | — | Stores |
| ProductionAgent | R0 | — | R0 | R0 R1 (report entry draft from user's words) R2 (submit entry) | R0 | R0 | — | — | — | Prod |
| QualityAgent | — | — | R0 | R0 | R0 R1 (inspection draft) | — | — | — | — | Quality |
| MaintenanceAgent | — | R1 (spares req) | R0 | R0 (downtime) | — | R0 R1 | — | — | — | Maint |
| FinanceAgent | R0 | R0 | R0 | R0 | — | — | R0 R1 (journal draft, reminder draft) | — | R1 | Finance |
| HRAgent | — | — | — | — | — | — | — | R0 R1 (own-scope) | — | HR |
| CustomerServiceAgent | R0 (customer's own orders) | — | R0 (availability) | R0 (ETA) | — | — | R0 (own balance) | — | R1 | — |

R3 actions (post, approve, send externally, price/credit changes) are *proposed* by any agent
with the matching module permission and always routed to approvals.

MVP delivery: read tools + KPI explanations first; drafts next; R2 only after tool-permission
and injection test suites pass.

## 5. Tool contract

```php
interface BusinessTool {
  public function name(): string;           // inventory.get_stock
  public function schema(): array;          // JSON Schema for input
  public function risk(array $input): Risk; // may depend on amount
  public function permission(): string;     // sales.quotation.create
  public function handle(array $input, ToolContext $ctx): ToolResult; // calls module Action
}
```

Every `ToolResult` includes `references[]` so answers are grounded.

## 6. Testing (mandatory)

- Per tool: unauthorised user denied, schema violations rejected, risk escalates correctly.
- Prompt-injection corpus (hostile text in customer names, notes, emails) must not trigger tools.
- Replay: recorded provider responses run deterministically in CI (fake provider).
- Budget/rate limit tests.

## 7. Cost and data protection

Usage metered per user/agent; monthly budget per customer with alerts. PII minimised in prompts
(masking of NIC, bank account, phone unless needed). Provider data-retention settings documented
per customer (see security.md).
