# ADR-0001 — Modular monolith, single-tenant deployments

Status: Proposed

Context: SME product, small team, many customers, each wanting data isolation.

Decision: One Laravel codebase organised into modules with enforced dependency rules; each
customer runs an independent deployment (own DB, secrets, storage). No shared multi-tenant DB.

Consequences: (+) simple code, no tenant-leak class of bugs, easy per-customer backup/restore.
(−) migrations and upgrades roll out per customer — mitigated by Coolify automation and
backward-compatible migrations. Microservices rejected as premature.
