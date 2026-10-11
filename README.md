# DIO AI ERP + MES

AI-powered ERP and manual MES (IoT-ready) for Sri Lankan SMEs — manufacturing and trading.
One shared codebase, independent deployment per customer (Coolify).

**Status:** Sprint 4 (Inventory: warehouses, stock ledger, lots and expiry, weighted-average cost, receipts, issues,
transfers, adjustments, reservations, reorder levels) complete and awaiting owner review — see
[docs/handover/sprint-4.md](docs/handover/sprint-4.md); earlier handovers: [Sprint 3](docs/handover/sprint-3.md),
[Sprint 2](docs/handover/sprint-2.md), [Sprint 1](docs/handover/sprint-1.md). Everything beyond Core, Approvals,
Master Data and Inventory (finance, sales, procurement, manufacturing, AI, mobile) is still design.

## Documentation

- Original brief: [docs/brief/original-brief.md](docs/brief/original-brief.md)
- Architecture: [docs/architecture/overview.md](docs/architecture/overview.md) and sibling files
- What Core actually does today: [docs/architecture/core-module.md](docs/architecture/core-module.md)
- What Master Data does today: [docs/architecture/masterdata.md](docs/architecture/masterdata.md)
- What Inventory does today: [docs/architecture/inventory.md](docs/architecture/inventory.md)
- Open decisions: [docs/architecture/decisions/0000-open-decisions.md](docs/architecture/decisions/0000-open-decisions.md)
- Roadmap & sprint backlogs: [docs/development-roadmap.md](docs/development-roadmap.md)
- Test strategy: [docs/test-strategy.md](docs/test-strategy.md)
- Developer setup: [docs/development/setup.md](docs/development/setup.md)
- Adding an approval-gated action: [docs/development/gating-an-action.md](docs/development/gating-an-action.md)
- Working agreement for Claude: [CLAUDE.md](CLAUDE.md)

## Stack

Laravel 13 (PHP 8.3+), PostgreSQL 16, Blade + Tailwind 4 + Alpine, Sanctum, spatie/laravel-permission,
Redis (queue, cache), Pest, Pint, Larastan. Planned: Horizon, Livewire where reactive, React Native, Claude API,
WhatsApp Business Platform, Docker, Coolify.

## Quick start

Requires PHP 8.3+ with `pdo_pgsql`, Composer, Node 22, PostgreSQL 16 (details and Windows notes in
[docs/development/setup.md](docs/development/setup.md)).

```bash
composer install
cp .env.example .env && php artisan key:generate   # then set DB_PASSWORD in .env
php artisan migrate --seed
npm install && npm run build
php artisan serve
composer check                                     # lint + static analysis + tests
```
