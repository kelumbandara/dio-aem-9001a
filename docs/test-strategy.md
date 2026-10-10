# Test Strategy

Framework: **Pest** on Laravel 13 (consistent across all modules). CI on PostgreSQL.

| Level | What | Examples |
|---|---|---|
| Unit | Pure domain logic, value objects, calculators | tax calc, UoM conversion, costing, OEE formula |
| Feature | HTTP/Livewire/console with DB | permissions per endpoint, document lifecycle, idempotency |
| Integration | Cross-module via events/outbox | invoice posted → journal created → TB balanced |
| Architecture | Module boundaries, approval gate coverage, no raw DB writes from AI | `arch()` tests |
| Reconciliation | Stock ledger ↔ balance; GL ↔ subledgers; AR/AP ↔ GL control accounts | run after randomised transaction sequences |
| Concurrency | Numbering, negative stock, double-posting | parallel process tests |
| API contract | OpenAPI schema conformance, error codes, pagination | generated spec vs responses |
| Security | AuthZ matrix, IDOR, CSRF, upload abuse, webhook forgery | per-resource generated tests |
| AI | Fake provider replay; tool authorization; injection corpus; approval routing; budgets | |
| Mobile | Jest unit/component; API mock contract; E2E (Detox) for key flows; offline-sync conflict tests | |
| Smoke | Post-deploy: health, login, queue, DB migration state | in CI and Coolify post-deploy |

Rules:
- Module not "complete" until: business rules tested, authz tested, reconciliation (where
  financial/stock) passing, docs updated.
- Finance and Inventory require property/randomised tests asserting invariants in database.md §3.
- Test data via factories; no reliance on seeded demo data.
- Coverage targets: ≥ 85% for domain services/actions; critical posting code 100% branch.
- CI gates: Pint, Larastan (level 6+ rising), Pest, arch tests, composer/npm audit.
- Never report results that were not actually run.
