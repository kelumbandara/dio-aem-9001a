# Branding step — handover

Status: **ready for owner review.** Stop point per CLAUDE.md. Details: [../architecture/branding.md](../architecture/branding.md). The agreed licensing plan is written down in [../architecture/licensing.md](../architecture/licensing.md) and is the next step.

## 1. What was built

**Administration → Appearance** (admin; menu entry with a palette icon), with a live preview beside the form:

- **Name and logo**: the name shown in the sidebar, page titles, sign-in page and documents; the logo (PNG, JPEG, WebP up to 1 MB) shown in the sidebar, on the sign-in page, as the browser-tab icon and on printed invoices and purchase orders; a sign-in message; a footer line for printed documents.
- **Colours**: nine ready-made colours or any colour of your own; the full set of shades is worked out for you; a colour too light for white button text is refused. The sidebar can be dark, light or in the main colour. Printed documents can be in colour or black and white.
- **Text and shape**: device font, Inter, Noto Sans, Open Sans or Roboto (all built into the product — nothing downloaded; Sinhala and Tamil always display properly); text size small / normal / large for the whole interface; square, standard or rounded corners.
- **Restore the standard look** button.
- **Vendor-only section** (visible only to the super-admin): switch off or reword the “Powered by” line. A customer's admin never sees it and cannot change it, even by crafting a request.
- **Everything uses the setting**: all existing screens (no per-screen work), the sign-in page, the sales invoice and the purchase order print. Printed documents of future modules get it by including three small components.
- **API** (`/api/v1/appearance`, logo upload, reset) so a mobile app can draw itself the same way.
- Also: the licensing plan you agreed is written up in [licensing.md](../architecture/licensing.md).

## 2. Verified results (dev machine: PHP 8.3.33, PostgreSQL 16.15, Redis 7 in Docker)

| Check | Result |
|---|---|
| `composer check` (Pint + Larastan level 6 + Pest) | Pint passed; Larastan **no errors**; **866 passed** (7,306 assertions), 902 s — a clean full run after the last code change, with PostgreSQL and Redis up |
| New tests | 25: colour scale 4 (unit), Appearance 21 (screens, logo, permissions, vendor line, documents, API), plus print-branding checks in the Sales and Procurement suites |
| Production build | `npm run build` passes; fonts and the new CSS entry are committed in `public/build` |

What the tests prove beyond "it runs":

- **Colour maths**: step 600 is the chosen colour, shades run from lightest to darkest, contrast is measured the WCAG way, every ready-made colour passes the readability limit, and yellow or pale blue are refused.
- **It reaches the screens**: a saved teal / brand-sidebar / Inter / large / rounded look appears in the dashboard's page head and on the sign-in page; the logo, the name and the footer line show where they should; reset restores the standard look.
- **Logo safety**: an SVG with a script, a text file named .png, a 10 px picture, a 3000 px picture and a 2 MB file are all refused, nothing is stored; replacing a logo deletes the old file; the public logo address is served as an image with `nosniff` and long caching.
- **Who may change it**: a user without the permission gets 403 on the screen, the save, the reset and the preview; the admin role has the permission but **not** the vendor one; a customer's admin sending “Powered by” fields changes nothing; the super-admin can switch the line off and it disappears from the sidebar, the sign-in page and documents.
- **Documents**: the printed tax invoice and purchase order carry the saved accent colour, font and footer line.
- **API and screen**: the same rules over HTTP; the screen opens in all three languages without raw keys and the translation files have identical keys.

## 3. NOT verified

- **No visual check in a browser by me** — please open Administration → Appearance, change the colour and watch the preview and a few screens; print an invoice.
- **Sinhala and Tamil labels are drafts** needing native review.
- **Fonts in the printed pages** are loaded through the same Vite build; I checked the generated markup and the built files, not the rendering of each font on paper.
- **No dark mode**, no per-user themes, no custom font upload (see branding.md §3).
- **Text size and the preview**: the preview approximates the size by zooming; the real screens use the exact size.
- The built asset files grew by about 380 KB (the fonts, committed in `public/build`).

## 4. Decisions for you

1. **“Powered by” line** — it is on by default and only you (the super-admin) can switch it off or reword it. Say if you want the default off, or the wording changed (default “Powered by DIO ERP”; set `VENDOR_NAME` in the environment).
2. Whether to also offer **alternative invoice layouts** later (this step only re-colours and re-fonts the one layout we have).

## 5. Unresolved risks

| Risk | Impact | Suggested handling |
|---|---|---|
| Colour choices are free for customers | an ugly or low-contrast look | the readability check blocks the worst; the reset button restores the standard look |
| Logo is stored on the server's private disk | lost if the disk is not backed up | include `storage/app/private/branding` in backups (Coolify volume) |
| Logo served by a PHP route | slightly more work per image request than a static file | cached for a year by the browser; a CDN can front it later |

## 6. Next

**Licensing** (agreed plan in licensing.md): first the Licensing module in this product (signed licence, warnings, grace → read-only → lock, a licence generator for you), then your separate vendor billing project (bank-transfer entry, PayHere). Then **Sprint 8 (Manufacturing I)** — the questions listed at the end of the Sprint 7 handover are still waiting for your answers.

## 7. How to try it

Sign in at <http://127.0.0.1:8000> as the admin (the demo admin is a super-admin, so you will also see the vendor section) and open **Appearance** in the sidebar.
