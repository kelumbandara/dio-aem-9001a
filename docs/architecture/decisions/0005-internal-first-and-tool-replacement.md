# ADR-0005 — Internal-first product; replace existing tools

Status: Proposed (from owner, 2026-10-10)

Context: The system is first for DIO's **own use**; selling to other SMEs is a later consideration.
DIO currently uses FrontAccounting (finance), Hostinger email, WhatsApp Business, an SMS gateway,
Jira, and HR/payroll software. The platform should eventually cover all of them.

Decisions:
1. **Build for DIO first, keep the architecture sellable.** Keep per-customer deployment and
   module toggles, but defer multi-customer concerns (onboarding automation, billing, white-label).
2. **Replacement map** (target modules; each is a roadmap item, not MVP-sprint-1):

| Current tool | Replaced by | Notes |
|---|---|---|
| FrontAccounting | Finance + Sales/Procurement/Inventory | Needs a **data migration/import** (CoA, customers, suppliers, opening balances, open AR/AP, history optional) and a parallel-run period with reconciliation against FA |
| Hostinger email | Communications (email channel) | Integrate via IMAP/SMTP first (keep Hostinger mailboxes); full mailbox hosting is NOT a goal |
| WhatsApp Business | Communications (WhatsApp Cloud API) | Number migration to the official API must be planned (a number can't be on app and API at once) |
| SMS gateway | Communications (SMS channel adapter) | Provider adapter; need provider name/API |
| Jira | Tasks/Projects module (lightweight work items, assignments, boards, linked to ERP documents) | New module `Tasks`; import of Jira issues optional |
| HR & payroll software | HR module (employees, attendance, leave) + Payroll (EPF/ETF, APIT, payslips, GL posting) | Statutory rules must be validated with an accountant; payroll after Finance |

3. Roadmap impact: add modules **HR/Payroll**, **Tasks**, **Communications SMS**, and a
   **Migration** toolkit; Finance acceptance includes a reconciliation vs FrontAccounting.
4. Open questions to owner: which HR/payroll software and what data export; SMS provider; Jira
   usage depth; number of users; FrontAccounting version/DB access for import.
