# Architecture Overview — DIO AI ERP + MES

Status: **Sprint 1 done (Core + module framework implemented, see [core-module.md](core-module.md)); everything else is still design.**

## 1. Concept

An AI-powered ERP + manual MES engine for Sri Lankan SMEs (manufacturing and trading).
"AI-powered" means: AI **reads** authorised business data, **explains** it, **drafts** documents,
and **proposes** actions. Deterministic backend services **execute** them, and humans **approve**
anything risky. AI never posts stock or money by itself.

## 2. Concept-fit assessment

| Concept requirement | How the architecture satisfies it | Risk / note |
|---|---|---|
| AI-powered ERP+MES for SL SMEs | Dedicated `AI` module; agents use only registered business tools that call the same domain services as the UI | AI value depends on clean data; MVP agents are read/draft first, write later |
| One engine for trading and manufacturing | Manufacturing modules are optional; module toggles per customer | Needs feature-flag tests for both profiles |
| SME price/ops sensitivity | Modular monolith, one Laravel app, single Docker image, low ops overhead | Per-customer deployments multiply ops work — automate via Coolify templates |
| Sri Lankan context | LKR base, VAT/SSCL/NBT tax codes, Sinhala/Tamil/English UI, WhatsApp-first comms, fiscal year Apr–Mar configurable | Tax rules change; tax codes are data, not code. Statutory returns are post-MVP |
| Independent customer deployments | One image/codebase, one DB + secrets per customer (no multi-tenant DB) | Migrations must be rolled out per customer; see deployment.md |
| Manual MES now, IoT later | MES records are source-agnostic (`source` = manual/device); device events feed the same tables | Counters from devices need dedupe/idempotency — designed in mes-iot.md |
| Safe AI | Tool registry + authorization + risk policy + approval engine + audit | Prompt-injection tests are mandatory before any write-capable tool ships |
| Web + mobile same rules | Mobile consumes versioned REST API; no business logic in the app | Offline drafts must reconcile via idempotency keys |

**Verdict:** the brief fits the concept. Gaps to close in review are listed in
[decisions/0000-open-decisions.md](decisions/0000-open-decisions.md). The two biggest are scope size
(the brief is a multi-year product; MVP slicing is in `docs/development-roadmap.md`) and local
tooling (PHP 8.3 + PostgreSQL are not yet installed on the dev machine).

## 3. Architectural style

- **Modular monolith**, Laravel 13 / PHP 8.3+. One repo, one deployable image per customer.
- Modules live in `app/Modules/<Module>` (or `modules/` — see ADR-0002) with their own
  Models, Actions, Services, Policies, Http, Events, Listeners, Database/Migrations, Tests.
- Modules talk through **public contracts** (interfaces, DTOs, domain events), never through
  each other's Eloquent models or tables. Rules in [modules.md](modules.md).
- Web UI: Blade + Tailwind + Alpine, Livewire only for reactive screens. Mobile: React Native
  against `/api/v1`.
- Async: Redis queues + Horizon; transactional **outbox/inbox** for reliable integration events.
- Posting (stock, GL) is done only by deterministic domain services inside DB transactions,
  producing immutable ledger rows; corrections are reversals.

## 4. Layer view

```
 Blade/Livewire  RN Mobile  WhatsApp/Email  AI chat
        \            |            |            /
         ---- HTTP controllers / API resources / webhooks / AI tool adapters ----
                              |
                  Form Requests + Policies (authz)
                              |
                  Actions / Application Services
                              |
        Domain services (posting, numbering, approvals)  --> Domain Events --> Outbox
                              |
                  Eloquent models + DB constraints
```

Rule: **every entry point (web, API, AI tool, queue job) calls the same Action/Service.**

## 5. Tenancy model

Single-tenant per deployment. `company_id` is NOT used for isolation (one company per install),
but multi-**branch** is supported (`branch_id` on transactional documents). This avoids tenant-leak
bugs entirely; isolation is physical.

## 6. Cross-cutting concerns

Audit log, document numbering, attachments, notifications, approvals, settings, module config,
idempotency keys, API versioning, error format — all in the `Core` module and documented in the
other files in this folder.

## 7. Document map

modules.md · database.md · events.md · api-standards.md · ai-agents.md · approvals.md ·
security.md · mes-iot.md · deployment.md · decisions/
