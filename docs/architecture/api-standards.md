# API Standards

## 1. Basics

- Base path `/api/v1`. Breaking changes → `/api/v2`; v1 supported for the life of released
  mobile apps. `X-API-Version` response header; deprecation via `Deprecation`/`Sunset` headers.
- JSON only, UTF-8, ISO-8601 UTC datetimes, decimals as **strings** (`"1250.5000"`).
- Auth: Laravel Sanctum personal access tokens for mobile/API (device-named, revocable, expiring);
  session cookie + CSRF for the Blade app. MFA required for privileged roles.
- Authorisation: Policies/Gates on every endpoint; permission names `module.resource.action`
  (e.g. `sales.invoice.post`).
- Controllers are thin: FormRequest → Action → API Resource.

## 2. Conventions

| Topic | Rule |
|---|---|
| Resources | plural kebab nouns: `/sales-orders`, `/purchase-orders/{id}` |
| Ids in URLs | `public_id` (uuid) |
| Verbs on documents | sub-resource actions: `POST /sales-invoices/{id}/post`, `/cancel`, `/reverse` |
| Pagination | `?page=&per_page=` (max 100), envelope `data`, `links`, `meta`; cursor for ledgers |
| Filtering/sort | `?filter[status]=posted&sort=-created_at`, whitelist per resource |
| Idempotency | `Idempotency-Key` header **required** on POST that creates/posts documents; same key + same body → same response; different body → 422 |
| Concurrency | `ETag`/`version` field; update with stale version → 409 |
| Rate limits | per user + per token; stricter for AI (`/ai/*`) and auth endpoints; `429` + `Retry-After` |
| Localisation | `Accept-Language: en|si|ta` for messages |
| Correlation | `X-Request-Id` accepted/returned, stored in audit logs |

### 2a. As implemented in Sprint 1

- Idempotency: scoped per user; only `2xx` responses are stored and replayed (header
  `Idempotent-Replayed: true`); a failed attempt releases the key. Missing header → `400`.
- Concurrency: integer `version` in the body of `PATCH` (not `ETag`/`If-Match` yet) → stale = `409`.
- Filtering uses `filter[...]`, sorting `sort=-field`, both whitelisted per resource; `per_page` max 100 (`422` above).
- Machine codes introduced so far: see [core-module.md](core-module.md) §6.

## 3. Response shapes

Success: `{"data": {...}, "meta": {...}}`

Error (RFC 7807-style):

```json
{
  "error": {
    "code": "inventory.insufficient_stock",
    "message": "Not enough stock for item ABC.",
    "details": [{"field": "lines.0.qty", "message": "…"}],
    "request_id": "uuid"
  }
}
```

Status codes: 200/201/204, 400 malformed, 401 unauthenticated, 403 forbidden, 404, 409
conflict/stale, 422 validation/business-rule, 423 period closed, 429, 500 (no internals leaked).
Business errors use stable machine codes (documented per module).

## 4. Approval-aware endpoints

Actions that need approval return `202 Accepted` with `{"data":{"approval_request_id":…,"status":"pending"}}`
rather than executing. Clients poll or are notified.

## 5. Mobile-specific

- Compact list resources + detail resources; `If-None-Match` support for dashboards.
- Offline drafts: client generates `client_uuid`; server upsert is idempotent; server returns
  authoritative state; conflicts surfaced (never silently merged for posted documents).
- Push token registration: `POST /devices/push-token`.

## 6. Contract testing

OpenAPI 3.1 spec generated and committed (`docs/api/openapi.yaml`); CI contract tests assert
responses match; mobile client types generated from it.

## 7. Webhooks (inbound)

Signature validation (HMAC) + timestamp tolerance + replay protection + dedupe via inbox;
respond 200 fast, process async.
