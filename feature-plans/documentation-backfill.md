---
path: /feature-plans/
status: in-progress
---

# Documentation backfill (v0.1.0)

## Goal

Bring `/documentation/` up to parity with what's already shipped. Most of v0.1.0 was built before the plan → implement → doc → changelog convention existed, so the codebase is well ahead of its docs.

## Current state

Documented (`/documentation/`):

- `books.md` — Book / Version / ReadInstance domain, creation flow, slug routing, custom PKs, dual-attached read instances, etc.
- `lists.md` — `BookList` / `ListItem`, `BookListPolicy`, reorder semantics, client-side stats.

Everything else in the app is undocumented. The features below are derived from `routes/api.php`, `app/Http/Controllers/`, `app/Services/`, `app/Models/`, `resources/js/views/`, `resources/js/stores/`, and `resources/js/router/`.

## Sequencing principle

Order by dependency: a doc should only reference concepts that have already been documented (or are about to be in the same tier). Books and Lists are already in place because everything either *is* a book or *contains* books. The remaining features fall into tiers built on top of that foundation.

## Roadmap

### Tier 1 — Taxonomy that books reference

These are the entities `Book` already points at. Documenting them next means the books doc can be cross-linked instead of duplicating their shape.

1. **Authors** — `AuthorController` (`/author/{slug}`, `POST /create-authors`), `AuthorService`, `AuthorsStore`, `AuthorView.vue`, `router/author-routes.js`. Cover: slug routing, `getOrSetToBeCreatedAuthorsByName` (the "find-or-stub" behavior used by the book-creation flow), the orphan-pruning behavior on book delete (already mentioned in books doc — link, don't repeat), and "related books by same author" surfacing.
2. **Genres** — `GenreController` (resource), `GenreStore`, `GenresView.vue` / `GenreView.vue`, `book_genre` pivot. Cover: how genres are attached during book creation, the index/show shapes, any genre-driven filtering.
3. **Formats** — `FormatController::store`, `ConfigController::getFormats`, `ConfigStore`, `FormatView.vue`, plus the admin `FormatsIndex` / `FormatsList` / `CreateFormat` components. Cover: why formats are exposed via `ConfigController` (they're effectively config data the SPA bootstraps with) and how `Book::formats()` is the through-versions convenience relation (already noted in books doc — link).

### Tier 2 — Book-adjacent flows

Once the taxonomy is documented, the multi-entity flows that span books + taxonomy can be written without forward references.

4. **New book creation flow** — `NewBookController` (`POST /create-book/title`, `POST /create-book`), `NewBookStore`, `NewBookView.vue`, `AddBooksView.vue`, `components/newBook/`. The two-step flow is summarized in `books.md` but the multi-step Pinia store, validation orchestration in `services/BookServices.js`, and "find existing vs. stub new" branching deserve their own doc — this is the most non-obvious flow in the app.
5. **Read history** — Covered partially in `books.md` (mutator doubling, dual FK, `Y-m-d` serialization). What's *not* there: `AddReadHistoryView.vue`, `CompletedView.vue`, the `/completed/years` and `/completed/{year}` aggregations, and how `BookService::getAvailableYears` / `getCompletedItemsForYear` shape the year-browse experience. Either expand `books.md` or split into a `read-history.md` — recommend split, since the year-browse UI is a distinct surface.
6. **Versions** — `VersionController::addNewVersion`, `AddVersionView.vue`, the "add a version to an existing book" path. Already touched in `books.md`; decide whether this warrants its own doc or stays as a section there. Recommend keeping in `books.md` — versions have no behavior independent of a parent book.
7. **Bulk upload** — `BulkUploadController`, `BulkUploadView.vue`. Cover: file format expected, validation, how it reuses (or bypasses) the book-creation pipeline, what happens on partial failure. This depends on the new-book-creation doc since they share the same downstream pipeline.

### Tier 3 — Cross-cutting features

Features that aggregate across everything in tiers 0–2.

8. **Statistics** — `StatisticsController::fetchUserStats`, `StatisticsService`, `StatisticsDashboard.vue`, `UserDashboard.vue`, `LibraryView.vue` (if it surfaces aggregated views). Cover: what numbers are computed server-side vs. client-side (contrast with lists, where stats are client-only), the user-scoping requirement (mirrors books/lists), and the rating-doubling caveat. Must be after read-history doc.

### Tier 4 — Platform

These touch every other feature but aren't "about" any one domain. Save for last so the cross-references can point at concrete docs.

9. **Authentication & users** — `UserController` (login/register/logout in `routes/web.php`), Sanctum token handling, `AuthStore`, `LoginView.vue`, `components/auth/UserLoginForm.vue`, axios bootstrap behavior. Cover: token attach in `bootstrap.js`, session vs. API auth split (web routes for login, `auth:sanctum` for API), and `User::user_id` custom PK. This is referenced by basically every other doc ("user-scoped via `auth()->id()`") so writing it last lets those docs link to a real definition.
10. **Admin surface** — `router/admin-routes.js`, `AdminHome.vue`, `AdminActionView.vue` (the `meta.component` indirection pattern), the format-management components. Cover: how `AdminActionView` dispatches to a named component via route meta — that's an extensibility seam future admin actions should follow.
11. **App shell & navigation** — `HomeView.vue`, `AboutView.vue`, `ErrorNotFoundView.vue`, `components/navs/HeaderNav.vue` and `SidebarNav.vue`, `router/index.js` composition of the per-domain route files, the SPA-fallback in `routes/web.php`. Lightweight doc; mostly a map of where layout/navigation lives so future contributors don't search for it.

## Touches existing systems

- `books.md` and `lists.md` will gain links once the tier-1 docs land. Avoid duplicating content already in those docs — link instead.
- `CHANGELOG.md` is currently empty for v0.1.0. Backfilling individual changelog entries for shipped work is **out of scope** for this plan; the docs themselves are the v0.1.0 record. A single `0.1.0` rollup entry pointing at the new docs is enough.
- Each doc lands as its own commit/PR so reviews stay scoped and the index in `MEMORY.md` / `CLAUDE.md` doesn't have to be touched repeatedly.

## Open questions

- **Split or expand?** Read-history and versions could each be their own doc or sections in `books.md`. Recommendation above: split read-history (distinct UI surface), keep versions in `books.md` (no independent behavior). Confirm before writing.
- **Per-doc plan files?** ~~The convention is plan → doc → flip-to-living. For already-shipped features, the plan file would be born at status `living` with only future-improvements / known-limitations. Worth deciding whether to skip the plan file entirely for backfilled features (no design context to capture) or create stub `living` plans for symmetry. Every feature is rough around the edges, so recommend that a plan should be included with improvements for each feature.~~ **Resolved (authors.md):** each backfilled feature gets a paired `living` plan with known-limitations + future-improvements. The authors pass surfaced enough rough edges (three divergent slug normalizers, no merge tool, dead endpoints, missing bio column) to justify the convention; apply it to the remaining tiers.
- **Admin scope.** The admin surface today is just format management. Worth deciding whether to write a thin admin doc now or wait until the second admin action exists (rule-of-three).

## Execution checklist

- [x] Tier 1: authors.md (+ `/feature-plans/authors.md` living plan)
- [x] Tier 1: genres.md (+ `/feature-plans/genres.md` living plan)
- [x] Tier 1: formats.md (+ `/feature-plans/formats.md` living plan)
- [ ] Tier 2: new-book-creation.md
- [ ] Tier 2: read-history.md (+ link from books.md)
- [ ] Tier 2: bulk-upload.md
- [ ] Tier 3: statistics.md
- [ ] Tier 4: auth.md
- [ ] Tier 4: admin.md
- [ ] Tier 4: app-shell.md
- [ ] CHANGELOG.md: single 0.1.0 rollup entry
