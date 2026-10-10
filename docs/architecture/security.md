# Security Architecture

Aligned with OWASP ASVS / Top 10 and OWASP Top 10 for LLM Applications. Sri Lanka PDPA
(Personal Data Protection Act No. 9 of 2022) considerations noted for customer data.

## 1. Identity and access

- Users (login accounts) separate from Employees (HR records); external types (customer/supplier
  portal users) have restricted guard and permission sets.
- RBAC via roles/permissions (spatie/laravel-permission or equivalent, open decision #4 in ADR-0000), plus record-level
  scopes (branch/department/own).
- Passwords: Argon2id/bcrypt, breach/strength rules, lockout + throttling. MFA (TOTP, recovery
  codes) **mandatory** for admin, finance-approver, and other privileged roles.
- Sanctum tokens: named per device, expiry, revocation, abilities; mobile stores in Keychain/Keystore.
- Sessions: secure/HttpOnly/SameSite cookies, CSRF on web, re-auth for sensitive actions.

> **Implemented in Sprint 1:** RBAC (spatie/laravel-permission, guard `web`), Sanctum tokens with
> expiry and revocation, login throttling, TOTP + single-use recovery codes with replay protection,
> MFA enrolment enforced for `super-admin`/`admin`, encrypted PII (`employees.nic`) and MFA secrets,
> append-only audit log with redaction. See [core-module.md](core-module.md) §4. **Not yet done:**
> password breach/strength rules, lockout beyond rate limiting, record-level (branch/department) scopes,
> CSP/HSTS headers, upload controls (Sprint 2), re-auth for sensitive actions.

## 2. Application security

- Form Requests validate everything; mass-assignment guarded; parameterised queries only.
- Output escaping by default (Blade); CSP header; HSTS; secure headers middleware.
- File uploads: allow-list mime/extension, size caps, random storage names, outside web root /
  object storage, checksum, optional AV scan hook, signed temporary URLs.
- Rate limits: auth, API, AI, webhooks. Idempotency on writes.
- Sensitive data masking in logs/UI/AI context (NIC, bank, phone, tokens). Encrypted casts for
  secrets/PII at rest where needed.
- Secrets only in environment/Coolify secrets; never in repo, never exposed to AI.
- Dependency scanning (composer audit, npm audit, Dependabot), SAST in CI.

## 3. AI-specific controls

- Acting-user authority only; tool allow-lists per agent; schema validation; risk policy; approvals.
- No SQL/shell/file/network tools. Outbound AI calls only to configured provider hosts.
- Prompt-injection: untrusted content is delimited and treated as data; tools never accept
  instructions from retrieved text; injection test corpus in CI.
- Output: references must resolve to records the user can see; redact on mismatch.
- Logging: prompts/responses stored per retention setting; PII masking; per-customer opt-out of
  provider training/retention as supported by provider terms.

## 4. Communications

Webhook HMAC verification + replay window; channel identity linking requires verified OTP/login;
consent/opt-out stored; WhatsApp templates approved; outbound sensitive content needs approval;
voice commands pass through the same auth/tool pipeline (no bypass).

## 5. Audit and monitoring

Append-only `audit_logs` (who/what/when/where/before/after/correlation). Security events
(login failures, permission denials, AI blocks) alerted. Health endpoints do not leak details.
Error monitoring (Sentry-compatible) with PII scrubbing.

## 6. Data protection and backup

Encryption in transit (TLS via Coolify/Traefik) and at rest (volume/object storage). Backups:
nightly DB dump + WAL/PITR where possible, to external object storage, encrypted, retention
policy, **restore tested** before any production go-live (checklist in deployment.md).

## 7. Customer isolation

Separate deployment, DB, credentials, storage bucket/prefix, Redis DB/instance, AI keys per
customer. No shared operational DB; support access through audited, time-limited accounts.

## 8. Security test plan

Authn/authz matrix tests, IDOR tests per resource, CSRF, upload abuse, rate limit, webhook
forgery, AI tool permission & injection tests, dependency audit in CI, pre-go-live checklist.
