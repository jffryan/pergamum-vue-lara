---
path: /feature-plans/
status: living
---

# Admin

Tracks rough edges and the (substantial) roadmap for the `/admin` surface. Descriptive content lives in `/documentation/admin.md`. The admin shell exists as one route + one dispatch view + one action (format management) and is unauthorized; almost everything below is "what should exist."

The defining tension here is that the codebase has *no* permission model. Today every logged-in user is implicitly an admin because there's only one user. The moment book ownership or a second account exists, half the items on this page become security bugs rather than UX gaps. Sequence the work below with that in mind: the authorization layer (item 1) gates almost every other improvement on this page.

That tension is now sharper than it was: `/admin/genres` shipped without item 1, so the surface can destroy data — merge deletes rows irrecoverably — while remaining reachable by any authenticated user. See `/feature-plans/genre-management.md` for the reasoning and the `GenrePolicy` seam left behind.

## Known limitations

### Authorization

- **No admin gate, anywhere.** No middleware, no `is_admin` column, no role / permission table. `GenrePolicy` exists but grants every ability to everyone — it's a seam, not a restriction. The `/admin` route and every endpoint behind an admin action (`POST /api/formats`, the genre CRUD and merge endpoints) are reachable by any authenticated user. Hiding the header link wouldn't help — the URLs are guessable and the API is open.
- **The header "Admin" link is shown to every logged-in user.** Even if you wanted "soft" gating today, the affordance is universal.
- **No audit trail, and now something irreversible to not have one for.** No "who created this format / deleted this book / merged these genres." Genre merge deletes the losing rows and their pivot rows outright, so a regretted merge has no record of what it consumed. `created_at` / `updated_at` exist on most tables but that's not the same as an action log. Item 3 has a concrete first customer now.

### Discoverability & UX

- **`AdminHome`'s derived menu has no grouping.** It renders every route carrying `meta.adminMenu` as a flat list of title + description. Fine at two actions; `meta.adminMenu.group` was sketched for this and isn't implemented.
- **No breadcrumbs / no "back to admin home" affordance.** From `/admin/formats` or `/admin/genres` the user has to use the browser back button or the sidebar.
- **No undo anywhere.** `ConfirmAction` gives destructive operations a confirm-with-impact step, but nothing is reversible once confirmed — genre merge least of all. Confirmation is not the same as recoverability.
- **`ConfirmAction` renders inline, not as a modal.** It appears in the flow of the table, which means on a long genre list the confirm for a row near the bottom can be off-screen from wherever the user's attention is. Acceptable at current scale; would want a focus trap and a modal treatment before it guards anything more consequential.

### Format management

- **`CreateFormat` is the only operation.** No rename, no delete, no merge, no soft-delete, no slug edit. Once a typo'd format exists there's no SPA path to fix it. The genre surface is now the worked example of what the fix looks like — conflict-checked rename, usage-guarded delete, N→1 merge — so this is a port, not a design problem.
- **`FormatsList` displays `format.name` only.** No usage count ("12 versions use this format"), no slug, no created date — nothing that would help an admin decide whether a format is safe to delete or worth renaming.
- **No "are you sure" / dry-run** for any future destructive format op. A format with versions attached should not be silently deletable.

### Architecture / extensibility

- **`meta.component` is a stringly-typed lookup.** A typo in the route's `meta` doesn't error — `<component :is="undefined">` just renders nothing. Now that `AdminActionView`'s map holds `defineAsyncComponent` entries, a missing key still fails exactly this silently. Either validate at router build time, or replace the indirection with a direct `component:` import per route (which would obviate `AdminActionView` entirely for most cases).
- **The `AdminActionView` indirection is justified by a future need that hasn't materialized.** Today, two of the three steps to add an admin action (route + map entry) could be collapsed into one (route with a direct `component:` import). The indirection only pays off when actions need a shared frame (sidebar, breadcrumbs, common chrome) — at which point `AdminActionView` should *provide* that frame, not just dispatch.
- **No shared admin layout component.** When a real admin shell lands (sub-nav, breadcrumbs, page title, action buttons in a consistent slot), every existing action will need to be retrofitted. Two actions in, this is already a retrofit rather than a greenfield design.
- **Domain stores own the admin data, not an admin-specific store.** `ConfigStore` holds formats, `GenreStore` holds genres — correct in both cases, because both are read by non-admin surfaces too. But it means admin-only mutations keep landing on stores that most of the app treats as read-only caches, and the line between "domain" and "admin" gets blurry. Worth deciding whether an `AdminStore` earns its keep or ops keep folding onto domain stores.

### What's missing from "admin" but probably belongs there

The codebase already has actions that *behave* like admin operations but live elsewhere because there's no admin authorization to migrate them behind. Each of these is a candidate to fold into `/admin/*` once a permission model exists:

- **Author merge / rename.** Mentioned in `/feature-plans/authors.md` as missing. Authors get duplicates from the find-or-stub flow; merging two author rows into one needs a deliberate UI.
- **Book delete.** `BookController::destroy` exists and is reachable by any authed user. In a single-user instance that's fine; with multiple users, deleting a book ripples through every user's lists and read history. Either it becomes admin-only, or per-user libraries replace the global catalog (see `/feature-plans/books.md`).
- **Bulk upload.** `BulkUploadView` lives at its own URL today but is functionally an admin operation (mass-imports into the global catalog). Could either stay where it is and gain an admin gate, or move under `/admin/bulk-upload`.
- **User management** (once registration is real). Listing users, deactivating a user, resetting a password as an admin, viewing per-user activity. None of this exists; all of it belongs here.
- **Catalog integrity tools.** Orphaned authors / genres / formats, books with no versions, versions with no read instances, read instances with mismatched `book_id` / `version_id` (see `/feature-plans/read-history.md`) — admin reports that flag and let an admin clean these up.
- **Format management completion.** Rename, delete (with usage-count guard), merge — same shape as the genre / author tools.

### Operational visibility

- **No "site stats" admin view.** Total users, total books, recent activity, error logs. The user-facing `/statistics` is per-user; an operator-facing equivalent doesn't exist.
- **No way to see recent errors / 500s from the SPA.** Sentry / Bugsnag / a homemade error log isn't wired up.
- **No queue / job monitor.** Today there are no queued jobs, but enrichment microservices are on the roadmap (`/feature-plans/enrichment-microservices.md`); they will need a job-status surface.
- **No feature-flag / kill-switch panel.** No flags exist today, but several plans (`/feature-plans/books.md` ownership, the Laravel 9→10 upgrade) will benefit from a way to gate features without a deploy.

### Tests

- **No tests for `FormatController::store`** beyond the validation rule (which is implicit). No tests for the admin route guard (because there isn't one). The genre endpoints behind `/admin/genres` *are* covered (`tests/Feature/Genres/`), but as API behavior, not as admin behavior — there's nothing admin-specific to assert yet.
- **No SPA tests for `AdminActionView`'s dispatch** — the meta-component lookup is the kind of thing that silently breaks on rename and only surfaces in manual QA. Same for `AdminHome`'s derived menu: a route that forgets `meta.adminMenu` just quietly doesn't appear.
- **No component tests for the destructive flows.** `ConfirmAction`'s consumers (`GenreRow`'s two-step delete, `MergeGenresBar`) branch on server reason codes, and a wrong branch is invisible until someone hits it. There's no Vue component-testing setup in the project to write them with.

## Future improvements

In rough priority order. **Items 1–3 are sequencing-critical** — most of the rest assume an authorization model exists.

### Foundations (do these first)

1. **Pick and ship an authorization model.** The simplest workable shape is an `is_admin` boolean on `users` plus a route middleware (`admin`) that 403s for non-admins. Apply it to: the `admin-routes` block (a route-level `meta: { admin: true }` checked in the router guard), the header link visibility, and any backend endpoint an admin action will hit. Bigger options (Spatie Permission, a roles table, per-resource policies) are overkill for v1; design so they can replace `is_admin` later without rewriting call sites. Coordinate with `/feature-plans/auth.md` since the change lives on `User`.
2. **Add an admin-only middleware and a backend gate** (`Gate::define('admin', fn ($user) => $user->is_admin)`). Apply it to `POST /api/formats` immediately as the canary. Any future admin endpoint inherits the same gate.
3. **Audit log table** — `admin_audit_log(id, user_id, action, target_type, target_id, payload, created_at)`. Even a write-only log with no UI is a huge step up; the UI to browse it can land later. Without this, every destructive admin op is unattributable.

### Admin shell

4. **Either build a real admin shell** (sidebar of actions, breadcrumbs, page title slot) **or delete `AdminActionView`** and have each route point at its component directly. The current indirection is overhead without payoff. Recommendation: build the shell, since items 5+ all want the same frame.
5. **Validate `meta.component` at router build time.** A small util that walks `admin-routes` and asserts every `meta.component` is in the dispatch map. Throw at boot; never silently render nothing.
6. **Group the derived menu.** `meta.adminMenu.group` was in the original sketch and isn't implemented — `AdminHome` renders a flat list. Worth doing once there are enough actions to need sections rather than a list.
7. **Give `ConfirmAction` a modal treatment.** It renders inline today, so a confirm for a row near the bottom of a long list can sit outside the user's attention. A modal with a focus trap before it guards anything more consequential than a genre.

### Format management completion

8. **Show usage counts** (`Format::withCount('versions')`) and the slug on the list view.
9. **Add rename + delete to formats.** Delete should refuse (or prompt confirm-with-reassign) when `versions_count > 0`. The genre surface is the worked example — conflict-checked rename via a service exception, usage-guarded delete behind a `force` flag, `ConfirmAction` for the confirm — so this is mostly a port.
10. **Move format ops out of `ConfigStore` into a dedicated `FormatsAdminStore`** if/when the surface grows past create + read. Keep `ConfigStore` doing what it's good at (bootstrap config).

### Sibling actions to migrate or build

11. **Author merge tool.** From `/feature-plans/authors.md`. Pick two `author_id`s, repoint `book_author` rows from the loser to the winner, delete the loser. Audit log entry. Wrap in a transaction. **Copy `GenreService::merge`'s shape, not a naive `UPDATE book_author SET author_id`** — `book_author` carries an `author_ordinal`, so a book credited to both the winner and the loser needs a deliberate answer for which ordinal survives, on top of the same duplicate-pivot hazard genres had.
12. **Book delete confirmation flow.** Either an admin-only operation under `/admin/books` with a confirm-with-impact-summary ("this will affect 3 lists and 7 read instances") or — depending on how `/feature-plans/books.md` resolves ownership — a per-user delete that doesn't need to live here. `ConfirmAction` is the component; the impact summary is the work.
13. **Migrate bulk upload under `/admin/bulk-upload`.** Or keep its current URL and add the admin gate. Decide based on whether non-admins should ever bulk-upload (today, they can).
14. **Catalog integrity dashboard.** A read-only admin view that lists: orphaned authors / genres / formats (zero references), books with zero versions, versions with zero reads, read instances with `book_id` ≠ their version's `book_id`. Each item linkable to its detail page; some entries get a one-click cleanup once the destructive ops above exist. Orphaned genres are now one click from cleanup via `/admin/genres`, which is the pattern the rest should follow.

### User management (depends on registration UI from auth plan)

15. **User list view** (`/admin/users`). Email, name, created date, last login, role, "deactivate" affordance. No edit-as-admin yet.
16. **Per-user activity drill-down.** Reads, lists, reviews — the same data the user sees on their own dashboard, scoped to one user_id.
17. **Admin password reset for a user.** Distinct from the user-initiated password reset in `/feature-plans/auth.md`; an admin can issue a one-time link.
18. **Promote / demote admin.** UI for flipping the `is_admin` flag added in item 1, with audit log entries.

### Operational visibility

19. **Site stats dashboard** (`/admin/stats`). Total users, total books, total reads, recent registrations, recent activity. Distinct from the per-user `/statistics` surface.
20. **Recent errors view.** If/when Sentry or similar lands, embed the recent-errors list. Until then, a tail of `storage/logs/laravel.log` is a poor-man's version.
21. **Job / queue monitor.** Required by `/feature-plans/enrichment-microservices.md`. Likely Horizon (Redis) or a custom view over `failed_jobs`.
22. **Feature-flag panel.** Even a hand-rolled `flags` table with on/off toggles is enough; replace with Pennant or LaunchDarkly later. Useful for the books-ownership and Laravel 9→10 work specifically.

### Cross-cutting

23. **Tests.** Feature tests for every admin endpoint asserting (a) non-admin gets 403, (b) admin gets 200 / 201, (c) audit-log row written — none of which can be written until item 1 exists. SPA tests for `AdminActionView` dispatch (renders the right component for the right `meta.component`, renders nothing + warns on missing), for `AdminHome`'s derived menu, and for the `ConfirmAction` consumers. The last group needs a Vue component-testing setup the project doesn't have.
24. **Documentation: keep `/documentation/admin.md` in sync** as actions land. The "Adding a new admin action" section there will need to evolve again when items 4–6 land.

## Open questions

- **Is single-user mode permanent?** Most of this roadmap only matters if a second account ever exists. If pergamum is staying single-user-per-instance, items 1–3 reduce to "rename `/admin` to be honest about being just a settings page" and items 12–18 mostly disappear. The decision lives in `/feature-plans/books.md` (ownership) and `/feature-plans/auth.md` (registration UI); coordinate before investing in the authorization model here.
- **Admin as "operator" vs admin as "data steward."** Catalog cleanup (items 12–16) is a different role from user management (17–20) and operations (21–24). Plausible that one user is a steward but not an operator. If multi-role becomes real, replace the boolean `is_admin` with a roles table — but only after the boolean has actually felt limiting.
- **In-app admin vs out-of-band tools.** Some items (catalog integrity, audit log review, queue monitoring) could live outside the SPA entirely (Tinker scripts, Horizon's own dashboard, a separate Laravel Nova-style backend). The trade-off: in-app keeps everything in one place; out-of-band reduces SPA surface area and avoids reimplementing what stock tools provide. Reassess before building items 21–23.
