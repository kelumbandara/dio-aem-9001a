# AI core (Sprint 11) and agents (Sprint 12): assistant, tools, drafts, audit, metering

Module key `ai`, folder `app/Modules/AI`. Design background: [ai-agents.md](ai-agents.md). Handovers: [Sprint 11](../handover/sprint-11.md), [Sprint 12](../handover/sprint-12.md).

## 1. What it is

A chat page (**Ask the assistant**, `/assistant`) and an API (`/api/v1/ai/…`) where a person asks a question in plain words ("How are sales this month compared with last month?", "Which customers owe us most?"). The assistant looks the figures up with **read-only tools**, answers briefly, and lists the pages the answer is based on. In Sprint 11 it could only read. Since Sprint 12 it has department agents and can also prepare **drafts** (quotations, purchase orders) for a person to review; it never sends, submits, approves, posts or changes anything (§7).

## 2. Decisions

| Topic | Decision | Why |
|---|---|---|
| Model access | `AiProvider` interface; `ClaudeProvider` calls the Anthropic Messages API. Model name, token limit, prices and limits are in `config/ai.php` (environment overrides). The key is read **only** from the environment (`ANTHROPIC_API_KEY`), sent only in the request header, never stored, logged, put in a prompt or shown in an error | Secrets never in files or the database; the model can be changed without code |
| Who the assistant is | It acts with **the asking person's permissions** and nobody else's. A tool is offered only if its module is switched on and the person holds the module's own view permission (the same one that opens the screen/dashboard). An unknown tool, a disabled module and a missing permission all give the same answer ("not available to you") | Model text is never an authorisation signal; no privilege escalation by wording |
| Tools | 20 read tools (risk R0), thin adapters over the same **metrics contracts** the Reporting dashboards use (sales, purchasing, stock, finance, production, quality), so the assistant and the dashboards cannot disagree. No SQL, shell, file or secret access exists for the model | Grounded figures; a small, auditable surface |
| Input checks | Every tool call is checked against the tool's JSON schema (types, required, enum, pattern, range; **unknown fields refused**) and then for sense (a real date, `to` not before `from`, at most two years). A refusal goes back to the model as an error it can correct | Models send malformed input |
| Prompt-injection boundary | Text from records (customer and item names, notes) is cleaned (control characters removed), shortened (120 characters, 15 rows), checked for instruction-like wording (flagged in the audit) and sent inside an envelope that says it is untrusted data. The system prompt says the same. The decisive protection is structural: even a model that obeys hostile text can only ask for tools the person may use | Defence in depth, tested with a model scripted to obey |
| Run loop | Questions per minute and monthly budgets are checked first; the question is saved; up to `max_steps` (6) provider calls, each tool request handled by the platform; the answer is saved with its references. Neighbouring turns by the same speaker are joined (an earlier failed question) | Bounded cost and time |
| Conversations | A person sees only their own conversations (404 for others, administrators included). Messages are **append-only** (database trigger). Empty conversations (the first question was refused) are not listed | Privacy, and an answer someone acted on stays readable |
| Audit | One immutable `ai_executions` row per run, successful or not: who, agent, model, hash of the prompt (the question text stays in the conversation, not in the audit), status, steps, every tool call (name, cleaned input, result status, flagged, milliseconds), tokens, cost, error code | Docs §4: "Immutable record of prompt hash, tool calls, decisions, results" |
| Metering | Cost = tokens × configured price per million (decimal arithmetic, 6 places, US dollars). Limits: questions per minute per person (10), monthly budget per person (USD 5) and for the company (USD 50), counted per Colombo calendar month. Over a limit, nothing is sent to the model; the attempt is recorded. Admin page *AI usage* and `GET /api/v1/ai/usage` | Cost control for a small company |
| Tool names | The provider accepts only letters, digits, `_` and `-` in a tool name, so `sales.summary` is sent as `sales__summary` and translated back (both directions, including earlier calls replayed in a conversation); a test checks every tool name stays valid and unique | Found when the first real call failed with status 400 |
| Idempotency | API posts use the `Idempotency-Key` mechanism: a repeated request is answered from the first run and the model is not asked (or paid) again | Retries on a bad network |

## 3. Structure

```
AI/
  Contracts/AiProvider.php, BusinessTool.php      the two seams
  Providers/ClaudeProvider.php                    Anthropic Messages API
  Services/Orchestrator.php                       the run loop
  Services/ToolRegistry.php  Tools/ToolCatalog.php  Tools/ClosureTool.php  Tools/SchemaValidator.php
  Services/SafetyPolicy.php  AgentRegistry.php  UsageMeter.php  ConversationService.php
  Models/Conversation, Message, Execution         tables ai_conversations, ai_messages, ai_executions
  Http/Controllers/{Web,Api}                      /assistant … and /api/v1/ai/…
```

Permissions: `ai.use` (ask; given to admin, accountant, sales, purchasing, production — not machine operators) and `ai.usage.view` (admin).

## 4. Tools

`sales.summary`, `sales.top_customers`, `sales.top_items`, `sales.open_orders`; `purchasing.summary`, `purchasing.top_suppliers`, `purchasing.open_orders`; `stock.valuation`, `stock.below_reorder`, `stock.expiring`, `stock.movement`; `finance.profit`, `finance.receivables`, `finance.payables`, `finance.cash`; `production.summary`, `production.targets`, `production.downtime`, `production.late_orders`, `quality.summary`. Periods are `from`/`to` dates (Colombo days, both included); the system prompt tells the model today's date and the financial year.

## 5. Going live

The default model is **Claude Haiku 5.5** (`claude-haiku-5-5`, chosen 2026-10-13: about USD 0.001 a question against about USD 0.036 with Sonnet 5.5; prices in `config/ai.php` are the provider's published ones). Switch with `AI_MODEL=claude-sonnet-5-5` if answer quality needs it. Add `ANTHROPIC_API_KEY=…` to the server's `.env` (never to the repository), optionally `AI_MODEL`, `AI_BUDGET_USER_MONTH_USD`, `AI_BUDGET_COMPANY_MONTH_USD`. Without a key the page says the assistant is not set up and nothing is sent anywhere. Check the provider's data-retention terms for the customer before enabling (see security.md).

## 6. Not built (next sprints)

Streaming answers; the ten department agents and drafts (Sprint 12); approvals integration for R2/R3; WhatsApp/e-mail channels (Sprint 14); document search (RAG); masking of national ID / bank numbers in prompts (the read tools return none today); per-department tool allow-lists; a provider other than Claude.

## 7. Sprint 12: agents, lookups and drafts

| Topic | Decision | Why |
|---|---|---|
| Agents | Seven agents besides the general assistant: owner's adviser (`ceo`), `sales`, `purchasing`, `inventory` (stores), `production`, `quality`, `finance`. Each has its own job description and a **tool list**: the matrix in ai-agents.md §4 is the maximum, and the person's permissions still apply to every tool. A person is offered an agent only if they could use at least one of its own-domain tools (a salesperson does not see the finance agent just because both can read sales totals). The agent is fixed when a conversation starts | Focused answers, a smaller attack surface per conversation, and the right agent for the right person |
| Not built | Maintenance and HR agents (no such modules), Customer-service agent (needs the customer channels of Sprint 14) | Nothing to read or draft yet |
| Lookups (read) | `master.find_customer`, `master.find_supplier`, `master.find_item` (code, name, unit, flags only: no prices, credit limits or tax numbers) and `stock.item` (quantity on hand, promised, available); each needs the matching master-data or stock permission | Exact codes for drafting; answers to "do we have X in stock?" |
| Drafts (R1) | `sales.draft_quotation` (sales agent), `procurement.draft_purchase_order` (purchasing agent) and `comm.draft_message` (sales and finance agents; payment reminder / document notice from a template, never sent by the AI, see communications.md). They go through **a contract owned by the module** (`QuotationDrafter`, `PurchaseOrderDrafter`), which re-checks the person's permission and then uses the module's normal service, so every rule (customer active, prices, VAT, numbering) is the one the screens use. The result is a real document in the **draft** state, created by the person (their name on it), with a visible note "Drafted by the AI assistant: please check…". | The assistant saves typing and looking up, a person reviews; docs §3 R1 |
| What drafting cannot do | A quotation draft takes only customer, items and quantities: **price, discount, tax and unit are never taken from the model**; lines are priced from the customer's price list (no price → the model is told and the person enters one). A purchase order draft may carry an agreed unit price (the buyer reviews it; submitting and the approval rules stay with a person). At most 20 lines. Never sent, accepted, submitted, approved, posted or paid | R3 actions (price/credit changes, posting, approving, sending) are always human |
| Draft limits | 3 drafts per question and 10 per person per hour (`AI_MAX_DRAFTS_PER_RUN`, `AI_DRAFTS_PER_HOUR`); the audit trail records each draft's document number | A confused or manipulated model cannot flood the lists |
| Risk levels in the registry | Only R0 and R1 tools exist; R1 is offered only to an agent that lists the tool by name; R2, R3 and R4 are refused by the registry whatever a tool says about itself | The ceiling is structural |
| Approvals integration | **Deliberately none in this sprint**: the AI never submits, so it never opens or answers an approval request. A draft appears in the normal flow; when the person submits it, the existing approval policies apply exactly as for any document. The approvals engine already accepts the `ai` channel (`gate(..., channel: 'ai')`) for later | Approval amounts and approvers for purchase orders are still undecided by the owner; the AI must not approve or submit around them |
| Explain a dashboard | Each Reporting dashboard has an "Ask the assistant to explain" link (for people who may use the assistant) that opens the chat with the matching agent and a ready-made question. Nothing is sent or paid for until the person presses Ask | KPI explanations, with the figures coming from the same tools |

### Safety suite (tests/Feature/AI/AgentsAndDraftsTest.php and OrchestratorTest.php)

Per tool: a person without the permission is not offered it and is refused if the model asks; bad input is refused (unknown fields, wrong types, bad quantities, too many lines); the risk level is as declared; no tool name suggests an action beyond drafting. Injection: a corpus of eight hostile texts placed in item names is read through the lookup tool by two agents while the scripted model "obeys" and asks for ten out-of-scope actions; every such call is refused, the text is cut/cleaned/flagged, and the number of quotations, purchase orders, sales orders, journals and stock documents is unchanged. Caps: a model told to "create 50 purchase orders" makes three. Replay: every run uses the scripted provider, so it is deterministic.
