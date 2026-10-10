# DIO AI ERP + MES

AI-powered ERP and manual MES (IoT-ready) for Sri Lankan SMEs — manufacturing and trading.
One shared codebase, independent deployment per customer (Coolify).

**Status:** Phase 0 — architecture documentation awaiting owner review. No application code yet.

## Documentation

- Original brief: [docs/brief/original-brief.md](docs/brief/original-brief.md)
- Architecture: [docs/architecture/overview.md](docs/architecture/overview.md) and sibling files
- Open decisions: [docs/architecture/decisions/0000-open-decisions.md](docs/architecture/decisions/0000-open-decisions.md)
- Roadmap & Sprint 1 backlog: [docs/development-roadmap.md](docs/development-roadmap.md)
- Test strategy: [docs/test-strategy.md](docs/test-strategy.md)
- Working agreement for Claude: [CLAUDE.md](CLAUDE.md)

## Planned stack

Laravel 13 (PHP 8.3+), PostgreSQL, Redis/Horizon, Blade + Tailwind + Alpine (+ Livewire),
React Native (TypeScript), Claude API, WhatsApp Business Platform, Docker, Coolify, GitHub Actions.

## Local setup

Will be added in Sprint 1 (requires PHP 8.3+ with `pdo_pgsql`, Composer, Node 22, PostgreSQL 16).
