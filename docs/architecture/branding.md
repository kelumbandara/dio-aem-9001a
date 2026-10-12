# Branding and appearance (Core)

Every installation looks like its company: name, logo, colours, font, text size and corners — chosen on
**Administration → Appearance** (`core.appearance.manage`, admin) with a live preview, applied to every screen and to every
printed document (invoices, purchase orders, and the documents of the modules still to come). Nothing needs rebuilding to
change it, and nothing is fetched from the internet.

## 1. What can be set

| Setting | Choices | Where it shows |
|---|---|---|
| Name | up to 60 characters (empty = product name) | sidebar, page titles, sign-in page, documents |
| Logo | PNG, JPEG or WebP, ≤ 1 MB, 32–2400 px | sidebar (on a white tile), sign-in page, browser tab icon, printed documents |
| Main colour | 9 ready-made colours or any hex colour | buttons, links, badges, focus rings, the document accent |
| Sidebar | dark, light, main colour | the left menu |
| Font | device default, Inter, Noto Sans, Open Sans, Roboto | all screens and documents |
| Text size | small 14 px / normal 16 px / large 18 px | the whole interface (everything is sized in rem) |
| Corners | square, standard, rounded | buttons, cards, inputs |
| Sign-in message | up to 200 characters | sign-in page |
| Document footer | up to 200 characters | foot of invoices and purchase orders |
| Print in black and white | on / off | printed documents use no colour |
| “Powered by” line | on / off, wording | sidebar, sign-in page, documents — **vendor only** |

## 2. How it works

- **One colour in, a whole scale out.** The chosen colour becomes step 600 of an 11-step scale (50 … 950, mixing towards white and black) that replaces the `indigo` colours the screens were written with. Because Tailwind v4 reads colours from CSS variables, overriding those variables in a small `<style>` block restyles every existing and future screen without touching it. The same block sets the corner radius, `--font-sans`, the root text size and the sidebar colours.
- **Readability is enforced, not hoped for**: a colour is refused when white text on it would have a contrast below 4.5 (WCAG AA). The nine ready-made colours all pass (tested).
- **Fonts are self-hosted** (`resources/css/fonts.css`, from the `@fontsource` packages; about 380 KB in total, committed in `public/build`). A browser downloads a file only when the chosen font needs it. The Sinhala and Tamil families are always in the stack after the chosen Latin font, so those scripts look identical on every computer.
- **The logo is private.** It is stored on the `local` (private) disk under a generated name, its type is read from its content (an SVG, a renamed script or a disguised file is refused — SVG can carry scripts), and it is served by `/branding/logo?v=<version>` with `nosniff`, a sandbox CSP and one-year caching. Replacing or removing it deletes the old file.
- **Documents.** Printed pages include `<x-core::branding.document-style />` (accent, font), `document-logo` and `document-footer`; a module's new printed document gets the branding by using those three components.
- **Performance.** The look is read from the already-cached settings, so a page costs no extra query. A change takes effect on the next request.
- **Who.** `core.appearance.manage` is held by `admin`. `core.appearance.vendor` is held by nobody: only the **super-admin** (the vendor, who hosts the installation) can switch off or reword the “Powered by” line; a customer’s admin never sees that section.
- **API** (`/api/v1`): `GET appearance` (any signed-in user: what an app needs to draw itself), `PATCH appearance`, `POST appearance/logo` (multipart), `DELETE appearance/logo`, `POST appearance/reset`; error `core.appearance_invalid`.

## 3. Known limits

- No dark mode (it would mean redesigning every screen); no per-user theme; no custom fonts beyond the five; no per-document logo.
- The preview applies text size by zooming the preview box; it is a close approximation, not pixel-exact.
- Alternative layouts for invoices and other documents (as opposed to colours and fonts) come later, once all documents exist.
