---
path: /documentation/
status: living
---

# Admin

## Scope

Covers the `/admin` SPA surface — the admin landing page (`AdminHome.vue`), the dispatch view (`AdminActionView.vue`) that resolves a route's `meta.component` to a real component, and the only admin action wired up today: format management (`FormatsIndex` / `FormatsList` / `CreateFormat`). The underlying `Format` model and `POST /api/formats` endpoint are documented in `formats.md`; this doc covers the admin shell that wraps them. Things that *could* live under admin but currently don't (genre management, author merge, bulk-upload, user management) are tracked in `/feature-plans/admin.md`.

## Summary

The admin surface is a thin SPA-only convention: a `/admin` landing page lists actions, each action is a route under `/admin/...` whose component is `AdminActionView`, and `AdminActionView` reads `route.meta.component` to pick which feature component to mount. There is exactly one admin action today (manage formats) and no admin-specific authorization — any logged-in user can see the "Admin" link in the header and reach the page.

## How it's wired

### Backend

- **Routes**: there is no `/api/admin/*` namespace. Admin actions hit the same endpoints normal flows use; for formats, `POST /api/formats` (`FormatController::store`) is the only one. Both the new-book flow and the admin formats page call it.
- **Controllers / services / models / policies**: nothing admin-specific exists. No admin middleware, no `is_admin` column on `users`, no role / permission table, no policy. The only gate is `auth:sanctum`.
- **Migrations**: none.

### Frontend

- **API layer**: none admin-specific. Format creation goes through `ConfigStore.createFormat` → `POST /api/formats`; see `formats.md`.
- **Stores**: `ConfigStore` (shared with the rest of the app — `formats` is bootstrap config, not an admin-owned slice).
- **Service**: none.
- **Routes** (`resources/js/router/admin-routes.js`):
  - `/admin` (`name: 'admin.home'`) → `views/admin/AdminHome.vue`.
  - `/admin/formats` (`name: 'admin.formats'`) → `views/admin/AdminActionView.vue`, with `meta: { component: 'FormatsIndex' }`.
- **Views**:
  - `views/admin/AdminHome.vue` — single hard-coded `router-link` to `admin.formats`. No dynamic listing of registered actions.
  - `views/admin/AdminActionView.vue` — reads `route.meta.component`, looks it up in a local `components` map, and renders it via `<component :is="…">`. Only `FormatsIndex` is in the map today.
- **Components** (`resources/js/components/admin/`):
  - `FormatsIndex.vue` — wraps `FormatsList` in `<Suspense>` (because `FormatsList` uses top-level `await`) and renders `CreateFormat` underneath.
  - `FormatsList.vue` — async `<script setup>`; awaits `configStore.checkForFormats()` and renders the format names. The `<Suspense>` parent is what makes the top-level await legal.
  - `CreateFormat.vue` — small form posting to `ConfigStore.createFormat`.
- **Header link**: `components/navs/HeaderNav.vue` shows an "Admin" link to every logged-in user (gated only on `authStore.isLoggedIn`, no role check).

## Non-obvious decisions and gotchas

- **There is no admin authorization, anywhere.** Any logged-in user sees the "Admin" link, can navigate to `/admin`, and can create formats. The admin pages are reachable by URL even if the link were hidden. This is the single most important thing to know about the surface — "admin" here means "the URL prefix", not "a privilege level."
- **`meta.component` is the extensibility seam.** New admin actions follow the same shape: register a route under `/admin/...` with `component: AdminActionView` and `meta: { component: '<NameInMap>' }`, and add `<NameInMap>` to the `components` object in `AdminActionView.vue`. Two-step add — both must happen, and the component name in `meta` must match the key in the map exactly (no error if it doesn't; the `<component :is>` just renders nothing).
- **The `components` map in `AdminActionView` is import-by-static-import, not lazy.** Every component listed in the map is bundled into the route chunk regardless of which action the user picked. Today that's just `FormatsIndex`; if the map grows, switch to `defineAsyncComponent` so each action loads its own chunk.
- **`AdminHome` is a hand-edited list of links, not derived from the route table.** Adding a new admin action means: (1) define the route in `admin-routes.js`, (2) add it to the `components` map in `AdminActionView`, (3) hand-add a `<router-link>` to `AdminHome`. Forgetting (3) leaves the action reachable by URL but invisible from the landing page.
- **`FormatsList` uses top-level `await`; `FormatsIndex` must keep the `<Suspense>` wrapper.** `FormatsList.vue`'s `<script setup>` calls `await configStore.checkForFormats()` at the top level, which only works inside a Suspense boundary. Removing the `<Suspense>` in `FormatsIndex` (or copy-pasting the pattern without it for a future async component) will produce a Vue warning and a never-resolving render.
- **`FormatsList` uses `:key="format.id"`, but the PK column is `format_id`.** All keys are therefore `undefined`. Vue still renders, but reconciliation falls back to index-based diffing and you'll get duplicate-key warnings in dev. The fix is one character (`format.format_id`); flagged in the plan.
- **`CreateFormat` exposes no edit / delete / reorder.** It can only add. Once a format exists there's no way through this UI to rename, soft-delete, or merge it. See `formats.md`.
- **The `/admin` route is gated only by the global `auth` guard.** Like every non-public route in `router/index.js`, `/admin` and `/admin/formats` redirect anonymous users to `/login`. There is no `meta: { admin: true }` flag and nothing in the guard reads one.

## Usage notes

### Reaching the admin surface

Logged-in users see an "Admin" link in `HeaderNav`. It points to `/admin`. From there, the only action is "Manage Formats" (`/admin/formats`). The admin pages are functionally part of the regular SPA — same nav shell, same auth gate, no privilege check.

### Adding a new admin action

1. Build the feature components (typically an `<Action>Index.vue` plus whatever child components it needs) under `resources/js/components/admin/`.
2. Add a route to `resources/js/router/admin-routes.js`:
   ```js
   {
       path: "/admin/<slug>",
       name: "admin.<slug>",
       component: () => import("@/views/admin/AdminActionView.vue"),
       meta: { component: "<ActionIndex>" },
   }
   ```
3. Import the index component in `views/admin/AdminActionView.vue` and add it to the `components` object.
4. Add a `<router-link :to="{ name: 'admin.<slug>' }">` to `views/admin/AdminHome.vue` so users can find it.
5. If the action calls a backend endpoint that should be admin-only, **note that no such gate exists today** — the endpoint is reachable by any authenticated user. Decide whether that's acceptable until proper admin authorization lands (see `/feature-plans/admin.md`).

## Related

- Plan file: `/feature-plans/admin.md` — known limitations and the (substantial) future-improvements roadmap.
- `/documentation/formats.md` — the format model, store, and `POST /api/formats` endpoint that the only current admin action wraps.
- `/documentation/auth.md` — the auth gate that's currently the only access control on `/admin`.
- `/documentation/app-shell.md` (planned) — covers `HeaderNav`, where the admin entry point lives.
