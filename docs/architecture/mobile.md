# Mobile app (Sprint 13)

A phone app for people who are away from a desk: approve requests, report production and machine stoppages on the shop floor, inspect, look up stock, ask the AI assistant, and receive notifications. Folder `mobile/` in this repository; handover: [Sprint 13](../handover/sprint-13.md).

## 1. Decisions

| Topic | Decision | Why |
|---|---|---|
| Technology | React Native with **Expo** (SDK 57), TypeScript, **Expo Router**, the stack the overview names (docs/architecture/overview.md) | One code base for Android and iPhone; managed builds; Expo Go for quick trials on a real phone |
| No business logic in the app | Every rule runs on the server through the same `/api/v1` the web screens' services use. The app validates only what is cheap and obvious (a number looks like a number) and shows the server's refusals on the right box | "Web and mobile follow the same rules" |
| Sign-in | E-mail, password and, when the account has one, the authenticator code, against `POST /api/v1/auth/login`. The returned token is named after the phone, expires with the server's setting (30 days), and is kept in the phone's **Keychain / Keystore** (`expo-secure-store`); signing out deletes it on the server too. A person who must still enrol the authenticator is told to do that once on the website | docs/architecture/security.md |
| One server address per company | The address of the company system is typed once on the sign-in page and remembered (and can be preset with `EXPO_PUBLIC_API_URL`), because every customer has their own deployment | Single-tenant product |
| What is shown | Tabs appear only for what the person can do (module switched on **and** permission held, from `/me` and `/mobile/config`). The server decides again on every request | Hiding is a convenience, never the protection |
| Offline entries | Production output and machine downtime can be entered with no signal. They are kept on the phone, in order, with an id that is both the `Idempotency-Key` and the `external_id`, and are sent when the app opens, returns to the front, every 30 seconds while something waits, or on "Send now". The same id is used by the first attempt and the queued one, so an answer lost on the way back can never become a second record. A refusal by the server (for example more than the order allows) is kept visible as "refused" with the reason, never silently dropped, and never resent by itself. Server failures are retried 5 times, in order. **Inspections, approvals and the assistant need a connection** because they depend on the current state | Hall signal is poor; but judging units or approving money on stale data is not safe |
| Shift and date | The phone asks the server which shift is running (`GET /shifts/current`) and puts the shift's own date on every entry, so a night shift entered after midnight, or an entry sent hours later, lands on the right shift date | The server rule (web screens) is the single source |
| Push notifications | The server queues a push to every phone a person registered (`POST /devices`) whenever an in-app notification is written (approval requests and decisions, …), through the Expo push service, in the language of that phone, with where to open it. Tapping opens the matching screen (approval, order, stock item). Dead devices are forgotten, signing out or dropping a sign-in removes the phone, at most 5 phones per person | Approvers should not need to open the app to learn that something waits |
| Languages | English, Sinhala, Tamil; the phone's language is chosen at first start, changeable in *More*; the server answers messages in the same language (`Accept-Language`). Sinhala/Tamil texts are drafts that fall back to English | Brief |
| Privacy of the screen | Cost and price figures of stock are not shown on the phone; the AI references open the app's own screens | Phones get lost |

## 2. Screens

Sign-in · **Home** (what is waiting, offline entries to send, shortcuts) · **Approvals** (for me / sent by me; detail with steps and decisions; approve, or reject with a reason; confirmation before sending) · **Production** (running orders; order page with *Report output*, *Report downtime*, *Inspect*) · **Stock** (search, where an item is) · **Assistant** (conversations, choose an agent, ask; "based on" links) · **More** (notifications, entries on this phone with discard, language, sign out).

## 3. Server additions

- `GET /api/v1/mobile/config`: company, modules switched on, oldest app version accepted (`MOBILE_MIN_APP_VERSION`; older apps are told to update), languages.
- `POST /api/v1/devices` / `DELETE /api/v1/devices/{id}`: register or forget a phone's Expo push address (table `mobile_devices`); `POST /auth/logout` also forgets the phone of that sign-in.
- `GET /api/v1/shifts/current`: the running shift and its shift date, or null.
- Push delivery: `InboxNotification` also uses `ExpoPushChannel`, which queues `SendPush` after the transaction commits; settings in `config/mobile.php` (`MOBILE_PUSH_ENABLED`, optional `EXPO_ACCESS_TOKEN` from the environment only).

## 4. Running it

1. `.\scripts\dev\run.ps1 -Lan` (website reachable from the phone; prints its address) and `.\scripts\dev\mobile.ps1` (installs packages on the first run, shows a QR code).
2. Install **Expo Go** on the phone, same Wi-Fi as the PC, scan the QR code, type the printed address into *Server address*.
3. Allow PHP through the Windows firewall when asked. Use `-Lan` only on a network you trust.

Limits of Expo Go: **remote push notifications are not reliable in Expo Go** (Expo removed them there on Android and limits them elsewhere); they need a built app (a development build or the store build). Everything else works. A store/production build needs: the Expo account project id (`extra.eas.projectId`), the app name and the bundle identifiers (currently placeholders `lk.dio.erp`: decision #15 in decisions/0000-open-decisions.md), app icons, an **https** address for the server (Android blocks plain http in built apps), and, for push on Android, an FCM key in the Expo account.

## 5. Tests and checks

`cd mobile; npm test` (Jest: API client, offline queue, formatting, permissions, translations: 47 tests), `npm run typecheck`, `npm run lint`, and `npx expo export --platform web|android` to prove the whole app bundles. Server side: `tests/Feature/Core/MobileTest.php` and `tests/Feature/Manufacturing/CurrentShiftApiTest.php`, part of `composer check`.

## 6. Not built

Barcode/QR scanning, photos on inspections, a screen for sales and purchasing documents, finance figures and dashboards on the phone, biometric unlock, a stopwatch for downtime, reading push delivery receipts, offline reading of data, automatic update prompts from the stores, app store packaging.
