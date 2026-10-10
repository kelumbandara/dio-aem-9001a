# CLAUDE.md — DIO AI ERP + MES

Read `docs/architecture/overview.md` and `docs/brief/original-brief.md` before changing anything.

## Non-negotiables
- Modular monolith in `app/Modules/*`; respect dependency direction in `docs/architecture/modules.md`.
  Cross-module use only via Contracts/DTOs/Events.
- Every entry point (web, API, AI tool, job) calls the same Action/Service.
- Stock and GL are posted only by domain services in DB transactions; ledgers are immutable,
  corrections are reversals. Money/qty = decimal, never float.
- AI never gets SQL/shell/secrets; acts with the requesting user's permissions; R3 actions need
  approval enforced in backend services (not UI).
- Single-tenant per deployment; no cross-customer data access.
- No placeholder logic presented as complete. No module is "complete" without passing tests
  and updated docs. Never fabricate test results.
- Ask before: destructive migrations, major architecture changes, production deploys, anything
  needing credentials (never write credentials into files).

## Conventions
- PHP 8.3+, Laravel 13, Pest, Pint, Larastan. FormRequests, API Resources, Policies.
- API under `/api/v1`, error format in `docs/architecture/api-standards.md`.
- UI strings via lang files (en, si, ta); English is the source.
- UTC in DB, Asia/Colombo for display; LKR base currency.

## Workflow
Work sprint by sprint (`docs/development-roadmap.md`). Stop for owner review at sprint boundaries.
After each sprint: update docs, write a handover summary, list unresolved risks.

## Commands
`composer test` · `composer lint` (`lint:fix` to apply) · `composer analyse` · `composer check` (all three) · `npm run dev` · `npm run build`
New terminal on Windows: `. .\scripts\dev\dev-env.ps1` (PHP 8.3 first), `.\scripts\dev\postgres.ps1 start`. Setup: `docs/development/setup.md`.
Portable PostgreSQL is not a service; tests need it running.
