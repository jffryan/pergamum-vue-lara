---
path: /documentation/
status: living
---

# Admin

## Scope

Covers the `/admin` SPA surface — the admin landing page (`AdminHome.vue`), the dispatch view (`AdminActionView.vue`) that resolves a route's `meta.component` to a real component, the shared destructive-action confirm (`ConfirmAction.vue`), and the three admin actions wired up today: format management (`FormatsIndex` / `FormatsList` / `CreateFormat`), genre management (`components/admin/genres/`), and location management (`components/admin/locations/`). The underlying models and endpoints are documented in `formats.md`, `genres.md` and `locations.md`; this doc covers the admin shell that wraps them. Things that *could* live under admin but currently don't (author merge, bulk-upload, user management) are tracked in `/feature-plans/admin.md`.

## Summary

The admin surface is a thin SPA-only convention: a `/admin` landing page lists actions, each action is a route under `/admin/...` whose component is `AdminActionView`, and `AdminActionView` reads `route.meta.component` to pick which feature component to mount. The landing page's link list is **derived** from the route table rather than hand-written — a route that declares `meta.adminMenu` shows up on it automatically.

There are three admin actions today (manage formats, manage genres, manage locations) and no admin-specific authorization — any logged-in user can see the "Admin" link in the header and reach the page. Genre management is the first admin surface that can destroy data, which is what `ConfirmAction` exists for.

## How it's wired

### Backend

- **Routes**: there is no `/api/admin/*` namespace. Admin actions hit the same endpoints normal flows use — `POST /api/formats` (`FormatController::store`), the genre CRUD + merge endpoints on `GenreController`, and the location CRUD endpoints on `LocationController`. Nothing about those endpoints is admin-only; the new-book flow calls the format one too.
- **Controllers / services / models / policies**: nothing admin-specific exists. No admin middleware, no `is_admin` column on `users`, no role / permission table. `GenrePolicy` exists but every ability returns `true` — it is a seam for a future gate, not a working restriction. The only gate is `auth:sanctum`.
- **Migrations**: none.

### Frontend

- **API layer**: none admin-specific. Format creation goes through `ConfigStore.createFormat` → `POST /api/formats` (see `formats.md`); genre mutations go through `GenreStore` → `api/GenresController.js` (see `genres.md`).
- **Stores**: `ConfigStore` and `GenreStore` — both shared with the rest of the app rather than admin-owned. `formats` is bootstrap config; `GenreStore.allGenres` backs the user-facing `GenresView` and `GenreTagInput` as well as the admin table.
- **Service**: none.
- **Routes** (`resources/js/router/admin-routes.js`):
  - `/admin` (`name: 'admin.home'`) → `views/admin/AdminHome.vue`.
  - `/admin/formats` (`name: 'admin.formats'`) → `views/admin/AdminActionView.vue`, with `meta: { component: 'FormatsIndex', adminMenu: { title, description } }`.
  - `/admin/genres` (`name: 'admin.genres'`) → same view, `meta: { component: 'GenresIndex', adminMenu: { … } }`.
  - `/admin/locations` (`name: 'admin.locations'`) → same view, `meta: { component: 'LocationsIndex', adminMenu: { … } }`.
- **Views**:
  - `views/admin/AdminHome.vue` — imports `admin-routes.js` and renders a link plus description for every route carrying `meta.adminMenu`. Nothing is hand-listed.
  - `views/admin/AdminActionView.vue` — reads `route.meta.component`, looks it up in a local `components` map of `defineAsyncComponent` entries, and renders it via `<component :is="…">`.
- **Components** (`resources/js/components/admin/`):
  - `FormatsIndex.vue` — wraps `FormatsList` in `<Suspense>` (because `FormatsList` uses top-level `await`) and renders `CreateFormat` underneath.
  - `FormatsList.vue` — async `<script setup>`; awaits `configStore.checkForFormats()` and renders the format names. The `<Suspense>` parent is what makes the top-level await legal.
  - `CreateFormat.vue` — small form posting to `ConfigStore.createFormat`.
  - `genres/GenresIndex.vue` — the genre action root: search box, `GenresTable`, `MergeGenresBar` (shown once ≥2 rows are checked), and `CreateGenre`. Fetches through `GenreStore.fetchAllGenres({ force: true })` on mount.
  - `genres/GenresTable.vue` / `genres/GenreRow.vue` — name (click to rename inline), `books_count`, merge-selection checkbox, delete button. See `genres.md` for the rename → merge handoff and the two-step delete.
  - `genres/CreateGenre.vue`, `genres/MergeGenresBar.vue`.
- **Shared components** (`resources/js/components/globals/`):
  - `ConfirmAction.vue` — the destructive-action confirm. Props `title`, `impact`, `confirmLabel`, `busy`; emits `confirm` / `cancel`. Genre delete and genre merge are its first two consumers.
- **Header link**: `components/navs/HeaderNav.vue` shows an "Admin" link to every logged-in user (gated only on `authStore.isLoggedIn`, no role check).

## Non-obvious decisions and gotchas

- **There is no admin authorization, anywhere.** Any logged-in user sees the "Admin" link, can navigate to `/admin`, and can create formats or delete and merge genres. The admin pages are reachable by URL even if the link were hidden, and so are the endpoints behind them. This is the single most important thing to know about the surface — "admin" here means "the URL prefix", not "a privilege level." It is a deliberate deferral on the strength of the shared-catalog decision in CHANGELOG 0.1.7, not an oversight; `GenrePolicy` is the seam a real gate would land on.
- **`meta.component` is the extensibility seam, and `meta.adminMenu` is the discoverability one.** New admin actions register a route under `/admin/...` with `component: AdminActionView`, `meta.component: '<NameInMap>'`, and `meta.adminMenu: { title, description }`. The component name in `meta` must match the key in `AdminActionView`'s map exactly — there's no error if it doesn't, the `<component :is>` just renders nothing.
- **`AdminHome` derives its list from `admin-routes.js`.** It filters the route table for `meta.adminMenu` and renders a link plus description for each. There is no second place to register an action for the landing page, so the "added a route, forgot the link" failure mode is gone.
- **The `components` map in `AdminActionView` uses `defineAsyncComponent`.** Each action loads its own chunk rather than every entry being bundled into the admin route chunk. Keep new entries in that shape; a static import re-introduces the problem for every action at once.
- **`FormatsList` uses top-level `await`; `FormatsIndex` must keep the `<Suspense>` wrapper.** `FormatsList.vue`'s `<script setup>` calls `await configStore.checkForFormats()` at the top level, which only works inside a Suspense boundary. Removing the `<Suspense>` in `FormatsIndex` (or copy-pasting the pattern without it for a future async component) will produce a Vue warning and a never-resolving render. The genre components deliberately don't use top-level await — `GenresIndex` fetches in `onMounted` with its own loading state, so it needs no Suspense parent.
- **Custom primary keys, in `:key` bindings too.** Every model on this surface uses a custom PK (`format_id`, `genre_id`), never `id`. `FormatsList` had `:key="format.id"` for a long time, which silently evaluated to `undefined` and dropped Vue back to index-based diffing. Both lists now bind the real column.
- **`ConfirmAction` lives in `globals/`, not `admin/`.** Book delete and the eventual author merge want the same component, and neither is under `/admin`. Its `impact` prop is the point of it — a confirm that only asks "are you sure?" is noise, so callers pass the concrete consequence ("this genre is on 14 book(s)").
- **Destructive genre operations confirm twice by design.** `GenreRow` sends the first `DELETE` *without* `force`, so the server decides whether books are attached and reports the authoritative count; only then does the confirm escalate to acknowledged wording and re-send with `force=true`. A genre nothing is tagged with is gone on the first click. See `genres.md`.
- **`CreateFormat` exposes no edit / delete / reorder.** It can only add. Once a format exists there's no way through this UI to rename, soft-delete, or merge it — the genre screen is the reference for what that looks like. See `formats.md`.
- **The `/admin` route is gated only by the global `auth` guard.** Like every non-public route in `router/index.js`, `/admin` and `/admin/formats` redirect anonymous users to `/login`. There is no `meta: { admin: true }` flag and nothing in the guard reads one.

## Usage notes

### Reaching the admin surface

Logged-in users see an "Admin" link in `HeaderNav`. It points to `/admin`, which lists "Manage Formats" (`/admin/formats`) and "Manage Genres" (`/admin/genres`). The admin pages are functionally part of the regular SPA — same nav shell, same auth gate, no privilege check.

### Adding a new admin action

1. Build the feature components (typically an `<Action>Index.vue` plus whatever child components it needs) under `resources/js/components/admin/`. Group them in a subdirectory once there's more than one, as `genres/` does.
2. Add a route to `resources/js/router/admin-routes.js`:
   ```js
   {
       path: "/admin/<slug>",
       name: "admin.<slug>",
       component: () => import("@/views/admin/AdminActionView.vue"),
       meta: {
           component: "<ActionIndex>",
           adminMenu: { title: "…", description: "…" },
       },
   }
   ```
   `adminMenu` is what puts it on the landing page — there is no link to hand-add.
3. Add it to the `components` object in `views/admin/AdminActionView.vue`, as a `defineAsyncComponent` so it gets its own chunk:
   ```js
   <ActionIndex>: defineAsyncComponent(
       () => import("@/components/admin/<path>.vue"),
   ),
   ```
4. If the action can destroy data, route the confirmation through `components/globals/ConfirmAction.vue` and pass a concrete `impact` string. Prefer letting the server report the impact (as the genre delete does) over trusting a cached count.
5. If the action calls a backend endpoint that should be admin-only, **note that no such gate exists today** — the endpoint is reachable by any authenticated user. Decide whether that's acceptable until proper admin authorization lands (see `/feature-plans/admin.md`).

## Related

- Plan file: `/feature-plans/admin.md` — known limitations and the (substantial) future-improvements roadmap.
- `/documentation/formats.md` — the format model, store, and `POST /api/formats` endpoint the formats action wraps.
- `/documentation/genres.md` — the genre CRUD + merge API, `GenreService`, and the store conventions the genre action drives.
- `/documentation/auth.md` — the auth gate that's currently the only access control on `/admin`.
- `/documentation/app-shell.md` (planned) — covers `HeaderNav`, where the admin entry point lives.
