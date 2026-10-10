# ADR-0000 — Open Decisions (need owner answers)

| # | Decision | Recommendation | Needed by |
|---|---|---|---|
| 1 | Local dev runtime: PHP 8.3 is required by Laravel 13; XAMPP has 8.2.12 | Install PHP 8.3 + `pgsql` ext (or Docker Desktop/WSL2) beside XAMPP; keep XAMPP for phpMyAdmin only | Before Sprint 1 code |
| 2 | Database: PostgreSQL vs MySQL | PostgreSQL 16 (ADR-0003); local install needed | Before Sprint 1 code |
| 3 | Laravel version | Laravel 13 (requires PHP 8.3+; Coolify image uses PHP 8.3/8.4) | Approve |
| 4 | Permission package | spatie/laravel-permission | Approve |
| 5 | Test framework | Pest | Approve |
| 6 | Module location `app/Modules` (PSR-4 `App\Modules`) | Yes | Approve |
| 7 | Livewire usage | Only for reactive tables/forms (production entry, approvals) | Approve |
| 8 | Sinhala/Tamil translations | Machine-draft + owner review; English is source language | Sprint 1 |
| 9 | Fiscal year default | 1 Apr – 31 Mar (SL statutory), configurable | Sprint 1 |
| 10 | Costing method | Weighted average for MVP; FIFO layers post-MVP | Before Inventory sprint |
| 11 | Taxes | VAT 18%, SSCL, NBT as dated, editable tax codes (verify current rates with accountant) | Before Finance sprint |
| 12 | Statutory outputs (VAT return, EPF/ETF, WHT) | Post-MVP | Roadmap review |
| 13 | AI provider data retention / PDPA stance per customer | Document in customer onboarding | Before AI sprint |
| 14 | Git remote | Owner to create GitHub repo; Claude never stores credentials | When ready |
| 15 | Company name/branding, domain, mobile bundle ids | Owner to provide | Before mobile sprint |
