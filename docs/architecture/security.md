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
> CSP/HSTS headers, re-auth for sensitive actions. **Sprint 2** added upload controls (content-based type check,
> allow-list, random private storage, size cap, signed short-lived downloads, antivirus hook – no scanner is wired,
> files are recorded as `skipped`), backend-enforced approvals (roles read through a directory so `super-admin` is
> not an approver; payload hash re-verified before execution), and audit retention by partition drop.

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

## 9. Sprint 15 security review — what was checked, found and changed (2026-10-13)

Method: route-by-route access inventory (automated, kept as a test), header and cookie review, authentication and password flows, code search (raw SQL, unescaped output, mass assignment, secrets), dependency audits, hand mutation checks of every new guard, and a live run of the production image.

| Area | Result |
|---|---|
| Public surface | 16 routes are reachable without signing in (listed in `tests/Feature/Core/RouteAccessTest.php`: login, password reset, health, webhook, signed unsubscribe, logo, locale, framework `up`/CSRF cookie). Any new unlisted route fails the test. |
| Permissions | The same test calls **every other route** (472 method+address pairs of the 522 in the product) as a person with no permission: nothing answers 2xx or 5xx; the 34 deliberately self-service routes are excluded (own notifications, own phone, own password, approvals inbox, appearance, licence status, reports index), each listed and explained. |
| **Found: server error on a malformed address** | A non-UUID/non-number in a web address (e.g. `/admin/users/abc`) made PostgreSQL refuse the value and the site answered **500**. Fixed centrally: model binding returns 404 for a malformed public id, and any "invalid input" database error becomes 404. Tested. |
| **Found: `/storage/{path}` route** | The framework's local-disk file route (including a PUT upload route) was enabled. Switched off (`serve=false`); test proves no such route. |
| **Found: no browser protections** | Added on every answer: `Content-Security-Policy` (own origin only; per-request nonce for scripts; `frame-ancestors 'none'`, `object-src 'none'`, `base-uri`/`form-action` self), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS on https. **Side effect found:** 12 confirmation dialogs and 2 print buttons used inline handlers the policy would silently block (a destructive button would have submitted without asking); all converted, and a test fails the build if an inline handler or an un-nonced inline script appears again. |
| **Found: no way to create users or change passwords** | A pilot could not start. Built: Users screens (add, roles, switch off, mailed password link, reset lost authenticator), forgot password by one-time link (same answer for every address, throttled, 3-hour life, single use), change own password, first-administrator command `app:create-admin`. Switching a person off, resetting a password or an authenticator, or changing a password ends their other sessions and tokens. Administrators cannot hand out roles beyond their own permissions, cannot change a super-admin, and cannot change their own roles or switch themselves off. All audited. |
| Passwords and lockout | At least 12 characters with upper and lower case and a digit, not containing the e-mail name, and (production default) not found in known breaches (k-anonymity service; fails open if unreachable). An account locks for 15 minutes after 10 wrong passwords or codes, from any address; the lockout is audited. |
| Cookies and sessions | Secure cookies and encrypted sessions by default in production; HttpOnly and SameSite=Lax; `TRUSTED_PROXIES` read from configuration at request time (a header saying "this was https" is believed only from a trusted proxy; tested for none, `*`, and lists). |
| Injection and output | Raw SQL appears only in migrations and as bound parameters; Blade output is escaped; the 5 unescaped outputs are a library-made QR code, validated theme CSS (hex colours and enumerated choices only) and the SVG chart (labels escaped). No `$guarded = []`; no CORS configuration (no cross-origin browser access). |
| Secrets | No keys or passwords in tracked files (pattern scan); `.env` and `deploy/*.env` are git-ignored. |
| Dependencies | `composer audit`: none. `npm audit` (server assets): none. Phone app `npm audit --omit=dev`: **`braces` (high, denial of service by deeply nested glob patterns) in the jest/metro build tooling**: not shipped in the app; the suggested fix downgrades Expo to version 44, so **accepted** until the Expo/React Native update. |
| Virus scan | ClamAV over its socket, fail closed (no scan = no upload). Verified against a real `clamd`: a clean file passes, the EICAR test file is flagged. |

**Accepted and open:** `'unsafe-eval'` stays in `script-src` because Alpine's standard build compiles the short expressions in our templates with `new Function`; removing it means moving to Alpine's CSP build and rewriting 43 inline expressions (follow-up). Re-authentication for sensitive actions, record-level scopes (branch/department), the admin lockout-by-attacker trade-off (an attacker who knows an e-mail can lock that account for 15 minutes; chosen over unlimited guessing), WAF/Fail2ban at the proxy, and Sentry-style error tracking are not built. No external penetration test was done; one is advisable before the first customer holds real data.
