# DIO ERP phone app

React Native (Expo) app for the DIO ERP + MES server in the folder above. Design and decisions: [docs/architecture/mobile.md](../docs/architecture/mobile.md).

```
npm install            packages (first time)
npm start              development server (scan the QR code with Expo Go)
npm test               unit tests (Jest)
npm run typecheck      TypeScript
npm run lint           ESLint
```

From the repository root, `.\scripts\dev\mobile.ps1` does the install and start; `.\scripts\dev\run.ps1 -Lan` makes the website reachable from the phone.

- `src/app/` screens (Expo Router: every file is a screen); `src/api/` the server client; `src/offline/` the queue for entries made without signal; `src/auth/` the signed-in session; `src/push/` push registration; `src/i18n/` English, Sinhala, Tamil; `src/ui/` shared look.
- No business rules live here: the server decides everything. See `AGENTS.md` for the Expo guidance this project follows.
- The server address is typed on the sign-in page, or preset with `EXPO_PUBLIC_API_URL`.
