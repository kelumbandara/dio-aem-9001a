# ADR-0003 — PostgreSQL as default database

Status: Proposed

Decision: PostgreSQL 16+ default. Reasons: stronger constraints (CHECK, partial/unique indexes,
exclusion), transactional DDL, row-level locking semantics suited to ledgers, JSONB, partitioning
for telemetry/ledger, optional pgvector for AI retrieval, first-class in Coolify.

MySQL/MariaDB: supported as best effort — migrations avoid PG-only features unless guarded by a
driver check; CI runs the suite on PostgreSQL (required) and MySQL (allowed to fail initially).

Dev impact: current XAMPP stack is MariaDB 10.4 and PHP lacks `pdo_pgsql`; PostgreSQL must be
installed locally (or via Docker) — see ADR-0000 item 1/2.
