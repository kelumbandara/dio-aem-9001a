# DIO AI ERP + MES

AI-powered ERP and manual MES (IoT-ready) for Sri Lankan SMEs — manufacturing and trading.
One shared codebase, independent deployment per customer (Coolify).

**Status:** Sprint 9 (Manufacturing II: output per shift, rework, quality inspection, downtime, finished goods and completion, see [docs/handover/sprint-9.md](docs/handover/sprint-9.md)), Sprint 8 (Manufacturing I: bills of materials, routing, production orders, material issue and return, see [docs/handover/sprint-8.md](docs/handover/sprint-8.md)), Sprint 7 (Procurement), the Branding step and the Licensing module (signed monthly licence, grace → read-only →
lock) and the separate vendor billing project (own repository in `C:\xampp\htdocs\dio-vba-9002a\ver1`) are complete and awaiting owner review — see [docs/handover/vendor-billing.md](docs/handover/vendor-billing.md), [docs/handover/sprint-7.md](docs/handover/sprint-7.md),
[docs/handover/branding.md](docs/handover/branding.md) and [docs/handover/licensing.md](docs/handover/licensing.md); earlier handovers: [Sprint 6](docs/handover/sprint-6.md),
[Sprint 5](docs/handover/sprint-5.md), [Sprint 4](docs/handover/sprint-4.md), [Sprint 3](docs/handover/sprint-3.md),
[Sprint 2](docs/handover/sprint-2.md), [Sprint 1](docs/handover/sprint-1.md).
Everything beyond Core, Approvals, Master Data, Inventory, Finance, Sales, Procurement and Manufacturing (reporting dashboards, AI, mobile)
is still design.

## Documentation

- Original brief: [docs/brief/original-brief.md](docs/brief/original-brief.md)
- Architecture: [docs/architecture/overview.md](docs/architecture/overview.md) and sibling files
- What Core actually does today: [docs/architecture/core-module.md](docs/architecture/core-module.md)
- What Master Data does today: [docs/architecture/masterdata.md](docs/architecture/masterdata.md)
- What Inventory does today: [docs/architecture/inventory.md](docs/architecture/inventory.md)
- What Finance does today: [docs/architecture/finance.md](docs/architecture/finance.md)
- What Sales does today: [docs/architecture/sales.md](docs/architecture/sales.md)
- What Procurement does today: [docs/architecture/procurement.md](docs/architecture/procurement.md)
- What Manufacturing does today: [docs/architecture/manufacturing.md](docs/architecture/manufacturing.md)
- Branding and appearance: [docs/architecture/branding.md](docs/architecture/branding.md); licensing plan: [docs/architecture/licensing.md](docs/architecture/licensing.md)
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
