# Communications (Sprint 14)

Sends e-mail and WhatsApp messages to customers, suppliers and staff, from templates, only to people who have allowed it, with delivery tracking. Handover: [Sprint 14](../handover/sprint-14.md).

## 1. Decisions

| Topic | Decision | Why |
|---|---|---|
| Consent belongs to the **address**, not the person | `comm_consents` is keyed by channel + address (e-mail lower-cased, phone as digits with country code, default Sri Lanka 94). The newest row decides. The table is **append-only** (database trigger refuses update and delete), so the history of who allowed or refused, when, and how (source, evidence) cannot be edited | A person can have two numbers; a number can change hands; proof must survive |
| Rules | WhatsApp needs an explicit **opt-in**. Marketing needs an opt-in on any channel. Service messages by e-mail are allowed unless opted out. **Opt-out always wins.** A reply of a stop word (`STOP`, `unsubscribe`, Sinhala/Tamil words…; "cancel" is deliberately not one) opts the number out at once; e-mails carry a signed one-click unsubscribe link | Law and WhatsApp's own rules |
| Templates only | A message is made from a template with named placeholders. Placeholder values are cleaned to one line. WhatsApp sends **approved template messages only** (Meta's rule); a WhatsApp template stays unusable until someone marks it approved after Meta has approved it | No free text, so nothing to inject |
| Sensitive templates need an approval | `payment_reminder` and `general_notice` are `sensitive`. Sending one opens an approval (`comm.message.send`, risk R3, policy "Sensitive messages", one `finance-approver` step) and the message is sent only when it is granted; consent and limits are checked again at that moment. A template that was sensitive can lose the mark only by a person with `comm.template.manage`, and that is audited | Money-related messages leave the company in its name |
| The AI can only draft | Tool `comm.draft_message` (R1) for the sales and finance agents, through the module's contract `MessageDrafter`: only `payment_reminder` / `document_notice`, only chosen fields, no free text, no address (taken from the master record), masked in the answer. The draft is marked `drafted_by_ai`; sending is a person's act. No tool sends, approves, edits templates or records consent | Owner decision: the AI never submits or approves |
| Limits | At most 5 messages per address per day (`communications.max_per_address_per_day`); 3 delivery attempts with back-off; the AI's draft limits (3 per question, 10 per hour) count these drafts too | Protects customers and the sender's reputation |
| Delivery tracking | `comm_messages` keeps status `draft → pending_approval → queued → sending → sent → delivered → read`, or `failed` / `cancelled`; WhatsApp status webhooks move it **forward only** (a late "sent" cannot undo "delivered"), failures keep the provider's reason (the access token is never in it) | Webhooks arrive out of order |
| Webhook safety | `GET/POST /api/v1/webhooks/whatsapp`: the verification handshake uses the verify token; every POST must carry a valid `X-Hub-Signature-256` (HMAC-SHA256 with the app secret, compared in constant time) or is refused; each event is stored once in `inbox_events` (a replay does nothing); events older than 7 days are ignored | Public endpoint |
| Identity linking | A person proves a phone or e-mail is theirs: a 6-digit code sent to it (10 minutes, 5 tries, 3 codes per hour, only the newest code works, only a fingerprint is stored, never the code). A linked number belongs to one person; linking records the opt-in (source `identity_link`). The page is "My phone and e-mail" | Needed before WhatsApp can reach staff or an AI-over-WhatsApp later |

## 2. Data (`comm_*`)

`comm_templates` (key, channel, locale, subject, body, purpose service|marketing, sensitive, approved, provider_template), `comm_consents` (immutable), `comm_messages` (direction in/out, channel, address, party, template, rendered subject/body, status, provider id, attempts, error, `drafted_by_ai`, related document), `comm_identities`, `comm_identity_challenges`.

## 3. Permissions and screens

`comm.message.view | draft | send`, `comm.template.manage`, `comm.consent.manage`. Menu group **Communications**: Messages, Templates, Consents, My phone and e-mail (everyone). API: `/api/v1/messages`, `/message-templates`, `/consents`, `/me/channels`, plus the public webhook. English complete; Sinhala and Tamil are drafts.

## 4. Contracts and events

Module-owned contracts `MessageDrafter` (draft only) and `Messenger` (used by notifications later). Reads a party's contact details through `MasterData\Contracts\PartyDirectory::contact()`. Events: `comm.message.sent`, `comm.message.failed` (see events.md).

## 5. Settings (all in `.env`; never in the repository or chat)

`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_API_VERSION` (default v21.0: check against Meta's current version), and the normal Laravel `MAIL_*` settings for e-mail. A channel without settings refuses to send with a plain message.

## 6. Not built

SMS (S14b), marketing campaigns, inbound conversations answered by the AI, forwarding in-app notifications to WhatsApp, attachments, per-customer language choice beyond the template locale.
