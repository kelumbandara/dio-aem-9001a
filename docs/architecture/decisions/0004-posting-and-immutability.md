# ADR-0004 — Deterministic posting and immutable ledgers

Status: Proposed

Decision: Stock and GL changes happen only in domain posting services inside DB transactions with
row locks (`SELECT … FOR UPDATE` on balances/period). Ledger rows are append-only; corrections are
reversal documents. Reconciliation tests (stock ledger ↔ balance, journals balanced) gate module
completion. LLMs and the UI never write ledger tables directly.
