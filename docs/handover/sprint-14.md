# Sprint 14 (Communications) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Design and decisions: [../architecture/communications.md](../architecture/communications.md).

## 1. What was built

- **Module Communications**: e-mail and WhatsApp messages from templates (invoice/payment reminder, document notice, general notice, identity code), a message list with status, and screens for templates, consents and "My phone and e-mail".
- **Consent**: kept per address, append-only; WhatsApp needs an explicit opt-in, opt-out always wins, a STOP reply opts out at once, e-mails carry a signed unsubscribe link.
- **Sensitive messages** (payment reminder, general notice) go through the approvals engine before sending (policy "Sensitive messages", one `finance-approver` step).
- **Delivery tracking**: queued → sent → delivered → read / failed, with retries; WhatsApp status webhook (signature checked, replays ignored, status moves forward only); inbound replies are stored.
- **Identity linking**: a 6-digit code to a phone or e-mail proves it is yours.
- **AI**: new tool `comm.draft_message` for the sales and finance agents: it prepares a draft from a fixed template, marked "drafted by AI"; it can never send, approve or change consent.
- API: `/api/v1/messages`, `/message-templates`, `/consents`, `/me/channels`, webhook `/api/v1/webhooks/whatsapp`. English complete; Sinhala/Tamil drafts.

## 2. Verified results (dev machine: PHP 8.3, PostgreSQL 16)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan + Pest) | Pint passed; Larastan **no errors**; **1,309 passed** (11,043 assertions), 1,441 s, one clean full run after the last change |
| Communications tests | 86 (address/template 11, message flow 26, webhook 18, identity 13, screens and API 18) |
| AI draft-tool tests | 7 (`tests/Feature/AI/MessageDraftToolTest.php`) |

Beyond "it runs": consent rules (opt-in, opt-out wins, per address); daily limit; sensitive gate and re-checks at approval time; webhook signature, replay and out-of-order status; OTP single use, expiry, attempt lock, newest code only, code never stored; the AI cannot add free text, pick `general_notice`/`identity_code`, or reach any send/approve/consent tool; a hostile customer name stays a one-line name in the draft; access token never appears in errors.

**Hand mutation checks: 9 of 9 caught** (signature check, status dedupe, inbound dedupe, OTP attempts saved, OTP attempt limit, sensitive gate, daily limit, consent at send, consent at delivery). My first run of this script used the wrong PHP and reported everything "survived"; I caught that, reran under PHP 8.3 and the files were restored.

Existing tests changed: tests that switch off Master Data/Approvals now also switch off Communications (it depends on them); the tool count is 27; the finance-agent prompt test now checks the read-only inventory agent.

## 3. NOT verified / not built

- **No real WhatsApp or e-mail was sent.** All HTTP and mail are faked in tests. The Graph API version (v21.0) and the exact template format must be checked against your Meta account.
- WhatsApp templates are seeded **unapproved**; they will not send until approved by Meta and marked approved here.
- Inbound messages are stored only: no AI over WhatsApp, no marketing campaigns, no SMS (S14b), no forwarding of notifications to WhatsApp, no attachments.
- Sinhala/Tamil strings are drafts. The screens were tested through HTTP, not looked at in a browser.

## 4. Decisions needed from you

1. Meta WhatsApp Business account: put the credentials in `.env` only (never in chat): `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`; submit the templates to Meta.
2. SMTP settings for the real mail server.
3. Who approves sensitive messages (now: role finance-approver) and whether a general notice should be sensitive.
4. Consent wording shown to customers.
5. Should in-app notifications also be forwarded to WhatsApp?
6. Still open from before: PO approval amounts, Horizon vs plain worker, ClamAV, GitHub Actions status, billing project's GitHub address; mobile sales/purchase screens.

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Meta rules/API changes | sends rejected | try with a test number first; the version is a setting |
| Sending untested against real providers | surprises on first send | send to your own number/address first |
| A wrong address on a master record | message to the wrong person | reminders are drafts, a person reads before sending; sensitive ones also need approval |
| Sinhala/Tamil drafts | poor wording | your check |

## 6. Owner's answers (2026-10-13) and what was done

| # | Answer | Done |
|---|---|---|
| 1 | WhatsApp: not bought yet, later | Nothing to do; the channel stays off until `.env` has the credentials |
| 2 | SMTP: Gmail or Hostinger? | Advice given in the chat (see below); settings go in `.env` by you, never in chat |
| 4 | Finance-approver approves sensitive messages | Already so |
| 5 | Consent wording: "By submitting this form, you consent to receive order updates and account notifications via WhatsApp." | Shown on "My phone and e-mail" under the number box, and stored as the note on the consent record (test added) |
| 6 | No forwarding of notifications to WhatsApp | Not built, as before |
| 7 | PO approvals from LKR 100,000 | Seeder, dev database and docs changed (before: 500,000): from LKR 100,000 a second approval by finance; tests added for 150,000 (two steps) and 59,000 (one step) |
| 8 | Laravel Horizon | `laravel/horizon` installed, configured (3 tries), dashboard for the administrator only, snapshot scheduled. **Not run here:** Windows lacks the needed extensions, so Horizon itself is untested until a Linux run; the gate is tested |
| 9 | ClamAV required | `ClamdAttachmentScanner` (streams to clamd, fails closed), switched on with `CLAMAV_ENABLED`. Tested against a stand-in daemon, **not against a real ClamAV** (none installed here) |
| 10 | GitHub Actions + billing repo | Billing project pushed to https://github.com/kelumbandara/dio-vba-9002a.git (branch main). CI: the last 3 runs on the product were success, success, **cancelled**: the Sprint 14 run hit the 20-minute limit (my full suite takes ~24 min). Limit raised to 45 minutes |

Changes in this step were checked with the related test files (131 pass); the full suite was not re-run for this step yet.
