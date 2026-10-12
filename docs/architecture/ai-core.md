# AI core (Sprint 11): assistant, tools, audit, metering

Module key `ai`, folder `app/Modules/AI`. Design background: [ai-agents.md](ai-agents.md). Handover: [Sprint 11](../handover/sprint-11.md).

## 1. What it is

A chat page (**Ask the assistant**, `/assistant`) and an API (`/api/v1/ai/…`) where a person asks a question in plain words ("How are sales this month compared with last month?", "Which customers owe us most?"). The assistant looks the figures up with **read-only tools**, answers briefly, and lists the pages the answer is based on. It can **only read** in this sprint; drafting and changing things come with the agents (Sprint 12) and always through the existing approval rules.

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

Add `ANTHROPIC_API_KEY=…` to the server's `.env` (never to the repository), optionally `AI_MODEL`, `AI_BUDGET_USER_MONTH_USD`, `AI_BUDGET_COMPANY_MONTH_USD`. Without a key the page says the assistant is not set up and nothing is sent anywhere. Check the provider's data-retention terms for the customer before enabling (see security.md).

## 6. Not built (next sprints)

Streaming answers; the ten department agents and drafts (Sprint 12); approvals integration for R2/R3; WhatsApp/e-mail channels (Sprint 14); document search (RAG); masking of national ID / bank numbers in prompts (the read tools return none today); per-department tool allow-lists; a provider other than Claude.
