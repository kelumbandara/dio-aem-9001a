# Sprint 12 (AI agents) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Design and rules: [../architecture/ai-core.md](../architecture/ai-core.md) §7.

## 1. What was built

- **Seven agents** besides the general assistant: owner's adviser, sales, purchasing, stores, production, quality, finance. Each has its own job description and its own list of tools; a person is offered only the agents that make sense for their permissions, and chooses one when starting a conversation ("Ask: Sales assistant — customers, orders, draft quotations").
- **Lookups**: find a customer, supplier or item by part of its name (code, name, unit only), and "how much of item X do we have?".
- **Drafts**: the **Sales assistant** can prepare a draft quotation and the **Purchasing assistant** a draft purchase order, from a conversation ("make a quotation for Lanka Traders for 10 widgets"). The result is a normal document in the *draft* state in your name, marked "Drafted by the AI assistant: please check…", with a link to review it. Nothing is sent, accepted, submitted, approved or posted.
  - A quotation draft cannot set prices or discounts (prices come from the price list; if none exists the assistant tells you to enter one).
  - Limits: 3 drafts per question, 10 per person per hour.
- **Explain this dashboard**: every Reporting dashboard has an "Ask the assistant to explain" link that opens the chat with the right agent and a ready question (nothing is sent until you press Ask).
- **Fixes found by using the real service** (your key): tool names with dots were refused by Claude (every question failed with status 400) — now translated automatically; PHP on your PC had no security-certificate list — added; error messages now carry Claude's own reason.
- Run by hand against real Claude on your development data: "How are sales this month compared with last month?" answered correctly from the tool (cost about USD 0.036).

## 2. Verified results (dev machine: PHP 8.3, PostgreSQL 16, Redis 7)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan + Pest) | Pint passed; Larastan **no errors** (the full run covers every app file); **1,197 passed** (10,439 assertions), 1,634 s, a clean full run after the last code change |
| Sprint 12 tests | 27 new in `tests/Feature/AI` (agents, lookups, drafts, safety suite) plus provider tests for tool names; `tests/Feature/AI` in all: 79 |

What the tests prove beyond "it runs":

- **Agents**: who is offered which agent; each agent's prompt differs and says what it may not do; an agent never gets a tool outside its own list even if the person may use it; draft tools are never given to the general assistant, the owner's adviser or an agent that does not list them.
- **Drafts**: a real draft quotation priced from the price list, marked and attributed; price, discount and any extra field refused (three ways); reasons for failure told to the model in plain words; bad quantities, empty and over-long lines refused; no permission → not offered, refused if asked, and refused by the module's own helper; a purchase order draft is not submitted, approved or received; per-question and per-hour limits (and per person).
- **What no agent can ever do**: no tool is named like an action beyond drafting; ten tool names for submitting, approving, posting, sending, changing prices, granting roles or deleting users, asked by each of the eight agents, are all refused and the count of quotations, orders, purchase orders, journals and stock documents does not change; tools of risk R2/R3/R4 are never offered whatever their permission or an agent's list says.
- **Prompt injection**: eight hostile texts planted in item names are read by two agents while the scripted model obeys them and asks for out-of-scope actions: all refused, text cut/cleaned/flagged, nothing created; a model told to "create 50 purchase orders" makes three.
- **Provider**: the tool-name translation both ways (including earlier tool calls sent back), every tool name valid for Claude and unique, error reasons passed on with the key scrubbed.
- **Hand mutation checks** — 12 caught: draft offered to everyone, high-risk tools offered, agent list ignored, per-question limit off, hourly limit ignoring the current question, hourly count counting nothing, quotation helper accepting price fields, permission checks removed from both helpers, agent availability by any tool, conversation's agent ignored, tool list not applied when running.

## 3. NOT verified / not built

- **Only one question was tried against real Claude** (a read question). Drafting through real Claude (does it ask for missing details, find the codes, make a sensible draft?) is tested only with the scripted model: please try it and tell me.
- **No approvals integration, on purpose**: the assistant never submits, so it never creates or answers an approval request; when you submit a drafted purchase order the existing approval rules apply as for any order (PO approval amounts are still undecided by you). The approvals engine already accepts an `ai` channel for later.
- Not built: Maintenance and HR agents (no such modules), Customer-service agent (needs Sprint 14 channels), drafts of stock adjustments, journals and production reports (production output has no draft state; stock and journal drafts were left out to keep this sprint safe), "R2 submit entry" for operators, streaming, document search, masking of IDs in prompts.
- A draft count race: two simultaneous questions from one person could together exceed the hourly limit by a few.
- I did not look at the pages in a browser. Sinhala/Tamil texts: labels only.
- A question costs about 3–4 US cents (most of it is the tool descriptions sent each time); fine for now, can be trimmed.

## 4. Try it

Sign in as an admin (or `sales@`/`purchasing@` demo users with the demo passwords) → **Ask the assistant**, choose an agent. Try: *Sales assistant: "Prepare a quotation for <a customer> for 5 <an item>"* — then open the link and review the draft (delete it afterwards).

## 5. Decisions for you

1. Are draft **quotations and purchase orders** the right first drafts? (Stock transfers/adjustments and journals are next candidates.)
2. Should the assistant be allowed to **submit** a drafted purchase order for approval itself later? Recommendation: no, keep submitting human; decide after the PO approval amounts are set.
3. Limits: 3 drafts per question / 10 per hour per person: right?

Still open from before: purchase-order approval amounts, Horizon vs plain worker, ClamAV, whether GitHub Actions is green, the billing project's GitHub address.

## 6. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| A draft contains a wrong customer/item/quantity | confusion if unreviewed | marked as AI draft, never sent or posted without a person; limits; audit lists every draft |
| Draft clutter | many AI drafts | per-question and per-hour limits; filter by "Drafted by the AI assistant" note |
| Model quality in Sinhala/Tamil | poor answers | native-speaker review before relying on it |
| Cost | bill | per-person/company budgets, usage page, provider-side spend cap |

## 7. Next

Sprint 13 (Mobile app) per the roadmap — or, if you prefer, first drafts for stock and journals, and the Sinhala/Tamil review.
