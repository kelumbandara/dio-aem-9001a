# Sprint 13 (Mobile app) — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Design and decisions: [../architecture/mobile.md](../architecture/mobile.md).

## 1. What was built

A phone app (folder `mobile/`, React Native with Expo) and the few server additions it needs.

- **Sign in** with e-mail, password and the authenticator code when the account has one; the sign-in is kept in the phone's secure store; the company's server address is typed once.
- **Home**: what is waiting for you, entries waiting to be sent, shortcuts. Only what the person may do is shown (module on + permission).
- **Approvals**: for me / sent by me, details with steps and decisions, approve, or reject with a reason, with a confirmation before sending.
- **Production**: running orders; per order *Report output* (shift preselected from the server, units made / rejected / rework, machine, notes), *Report downtime* (machine, reason, minutes), *Inspect* (accepted / rejected / rework with a reason).
- **Works without signal**: output and downtime entered with no connection are kept on the phone and sent automatically later, in order, never twice; a refusal by the server stays visible with its reason.
- **Stock**: search an item, where it is and how much is available (no cost figures on the phone).
- **Assistant**: choose an agent, ask, read answers with "based on" links that open the app's own screens.
- **Notifications and push**: inbox, tap to open; the server sends a push to the phone for every notification (approval requests and decisions…), in the phone's language.
- **English, Sinhala, Tamil** (Sinhala/Tamil are drafts that fall back to English).
- **Server additions**: `GET /mobile/config`, `POST/DELETE /devices` (push address), `GET /shifts/current`; logout forgets the phone; notifications queue a push after saving. Scripts: `.\scripts\dev\run.ps1 -Lan` (website reachable from the phone) and `.\scripts\dev\mobile.ps1` (start the app server).

## 2. Verified results (dev machine: PHP 8.3, PostgreSQL 16, Node 22)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan + Pest) | Pint passed; Larastan **no errors** (769 files); **1,216 passed** (10,498 assertions), 1,276 s, a clean full run after the last change |
| Phone app: `npm test` / `npm run typecheck` / `npm run lint` | **50 tests passed**; TypeScript no errors; ESLint no problems |
| Phone app bundles | `expo export` for **web** and **Android** (Hermes) both build the whole app (all screens) |
| Server tests added | 19: push and devices (`tests/Feature/Core/MobileTest.php`, 15) and current shift (`CurrentShiftApiTest.php`, 4) |

What the tests prove beyond "it runs":

- **App client**: token, language and JSON headers; every POST has an Idempotency-Key; the server's two error shapes become one error with the field it belongs to; an expired session signs out but a wrong password does not; a dead connection or a server that never answers is a retryable "network" error that never leaks the token; which statuses are worth repeating.
- **Offline queue**: order kept; ids unique and equal to the `external_id`; survives a restart; 25 simultaneous entries lose none; unreadable storage does not crash; sends oldest first with the entry id as key; stops on no connection, keeping everything untouched; a refused entry stays visible as "rejected" and is never resent; a failing server is retried 5 times in order and the next entry never jumps ahead; stops at once when the session ended; two simultaneous sends share one run; **an answer lost on the way back cannot become a second record** (the queued retry carries the same id).
- **Numbers, dates, links**: quantities accepted/refused exactly as the server does; money and quantities shown without ever passing through a float (20-digit number intact); dates as written (not shifted by the phone's time zone); only our own known paths from a notification open an app screen (`//evil.example` and `../..` are refused).
- **Who sees what**: nothing shown without the permission, nothing for a switched-off module; old app versions detected.
- **Push (server)**: text in each phone's language with where to open it; nothing for people without a phone, switched-off users, or when push is off; queued after the change is saved and no job at all for a person with no phone; access token only in the header; a dead phone is forgotten and others kept; the queue retries when the push service is down and gives up quietly on a refused request; only the newest 5 phones kept; only your own phone can be removed; **logging out removes that phone and no other**; bad push addresses, platforms, languages refused.
- **Running shift**: day shift, night shift, the evening date for a night shift after midnight, null between shifts and on a non-working day, permission and sign-in needed.
- **Against the real running server** (a script walking the calls the app makes, as the production demo user): sign-in, profile, settings, shifts, machines, reasons, orders, order detail, a refused output (422 with its field), the missing-key refusal, stock, an approvals inbox, notifications, assistant agents, registering and removing a phone, sign-out and a 401 afterwards. Nothing was written except the phone row, which it removed.
- **Hand mutation checks** — app: 16 of 16 caught (external id, stopping on no connection, giving up, the retry limit, reusing the id, serialising writes, sharing one run, session end, wrong-password handling, retry statuses, idempotency header, permission and module checks, quantity rule, link checks). Server: 10 of 11 caught; the survivor (removing the "has a phone" shortcut) led to a new test; one more change (trailing-newline guard in a regex) cannot matter over HTTP because the framework trims input first.

## 3. NOT verified / not built

- **I have not run the app on a phone or an emulator** (none available here): the screens were never seen or touched. They compile, bundle, type-check and lint, the logic behind them is tested, and the server calls were exercised for real, but layout, touch sizes, keyboard behaviour and the look in dark mode are **unproven until you try it** (Expo Go on your phone, see §4).
- **Push notifications were not tried end to end**: the server side is tested against a faked push service; they cannot work in Expo Go and need a built app, an Expo project id and (Android) a Firebase key.
- **First trial on a phone (Expo Go) found a defect, now fixed**: the notifications library, loaded at start-up, stopped the whole app in Expo Go. It is now loaded only in a built app; in Expo Go push is simply off and the rest works. Two tests (one proving the library is never loaded in Expo Go) and a hand mutation check cover it.
- Not built: barcode scanning, photos on inspections, sales/purchase/finance screens, dashboards on the phone, biometric unlock, downtime stopwatch, push delivery receipts, offline reading, store packaging and icons. Bundle id is `lk.diosolutions.erp` (your choice of company: DIO Solutions); app icon and store texts are not done.
- Demo data limit: the demo machines are all switched off, so the machine choice is empty in the demo (downtime needs a machine).
- A phone must reach the server: plain `http` works in Expo Go on your own network; a built app needs **https**.

## 4. Try it (about 10 minutes)

1. Terminal 1: `. .\scripts\dev\dev-env.ps1` then `.\scripts\dev\run.ps1 -Lan` — note the printed address (for example http://192.168.0.105:8000). Allow PHP in the Windows firewall if asked.
2. Terminal 2: `. .\scripts\dev\dev-env.ps1` then `.\scripts\dev\mobile.ps1` (first run installs packages) — a QR code appears.
3. Phone: install **Expo Go**, same Wi-Fi, scan the QR code. In the app type the address, sign in as `production@dio.test` or `approver@dio.test` (demo passwords as before).
4. Try: report output on the running order, turn on flight mode and report again (it says it is saved on the phone), turn it off and watch it send; approve a request created on the website; search stock; ask the assistant.

## 5. Your answers (2026-10-13) and what follows

1. Company **DIO Solutions**, bundle id chosen: `lk.diosolutions.erp`.
2. **Private install** is enough: see docs/architecture/mobile.md §4a (Android APK through the company's own Expo account).
3. Next phone functions: **sales and purchase screens** (not built yet; proposed as the next mobile step).

## 5a. Still open

App icon and store texts (not needed for a private install). Still open from before: purchase-order approval amounts, Horizon vs plain worker, ClamAV, whether GitHub Actions is green, the billing project's GitHub address.

## 6. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Screens untested on a device | layout or touch problems | the 10-minute trial above before anything else |
| Lost or shared phone | someone sees approvals | sign-in token expires in 30 days and stops working when an administrator disables the account; biometric unlock is a follow-up |
| Duplicate entries from a double send | double output | the same id is used for every attempt and the server records once (tested), so not expected |
| Push needs build + Firebase key | no push in Expo Go | set up the Expo/Firebase accounts when the app is packaged |
| Offline entries refused later (order completed meanwhile) | worker unaware | refused entries are kept visible with the reason on *More → Entries on this phone*; a banner on Home counts waiting entries |

## 7. Next

Sprint 14 (Communications: e-mail, WhatsApp, templates, consent) per the roadmap, after your review and the decisions above.
