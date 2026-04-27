---
path: /feature-plans/
status: living
---

# Formats

Tracks rough edges and follow-up work for the Formats taxonomy. Descriptive content lives in `/documentation/formats.md`.

Unlike Authors and Genres, formats are not created as a side effect of book creation — they are bootstrap config consumed by the book / version forms. Most of the rough edges below are concentrated in the create / browse paths and in the inconsistent ways the rest of the codebase identifies a format (by `name`, by `slug`, by hardcoded `format_id`).

## Known limitations

### Authorization & ownership

- **`POST /formats` is not admin-gated.** Sits inside `auth:sanctum` with no policy, no admin-role check, and no gate. Any authenticated user can create a format that then appears in every other user's format `<select>`. The `/admin/formats` route in `router/admin-routes.js` is "admin" by convention only — there is no router guard.
- **No user / role model for admin.** There is no `is_admin` column, no role table, no `Gate` definitions. Adding one is a prerequisite to gating any of the format-management endpoints (and to the broader admin surface tracked in `/feature-plans/documentation-backfill.md` tier 4).
- **No `FormatPolicy`.** Once update / delete endpoints land (see Future improvements item 4), a policy needs to land with them.

### Validation & request shape

- **No `FormRequest` class.** `FormatController::store` validates inline (`name|required|string|max:255|unique:formats,name`). Fine today; will diverge from the books / authors / genres pattern once those flows adopt FormRequests.
- **Slug uniqueness is not validated.** Only `name` is `unique:formats,name`. Two distinct names that slug-collide ("Sci Fi" / "sci-fi" both slug to `sci-fi`) will produce duplicate slug rows and break the `/formats/:slug` route silently — the index filter would return books for whichever row's name happens to match.
- **No name normalization.** `'  Audiobook  '` is accepted as a different format from `'Audiobook'` because the unique check is exact-match against the trimmed-only-by-MySQL value. Add `trim` + canonical casing in the controller (or a FormRequest mutator).
- **`BookController::index` reads `?format=` with no validation.** A missing or empty value falls through to "no filter" silently; an unknown value returns an empty result with no 404.

### Data integrity

- **`formats.name` is not unique at the DB level** — only the controller-side validation rule is. Concurrent inserts can race; a future bulk-create or seeder bypassing the controller can produce duplicates.
- **`formats.slug` is nullable, not unique, not indexed.** Existing rows from pre-`store`-rewrite seeding may still have `slug = null`; the `/formats/:slug` route silently breaks for them. There is no backfill migration. Adding a unique index will require a backfill + dedupe pass.
- **No format prune.** Deleting the last book / version that references a format leaves the `Format` row in place — it stays in the bootstrap list and the `<select>` forever. Mirrors the genre behavior; differs from author orphan-pruning.
- **`Format::books()` does not `distinct()`.** A book with two versions in the same format would surface twice. No code currently iterates this relation, but anyone reaching for "all books in this format" should be aware.

### Performance & query shape

- **The browse-by-format filter does not user-scope `readInstances`.** `BookController::index` eager-loads read instances scoped to `auth()->id()` for the row count, but the same filter applies on the format-filtered branch — confirm this still holds when read-history user-scoping is audited (see `/feature-plans/books.md`).
- **No index on `versions.format_id`.** Check the migration; the `format_id` column should be indexed because every browse-by-format query joins on it. (The FK constraint typically creates one, but worth verifying when the slug-vs-name fix lands.)
- **`ConfigStore.books.formats` is loaded once per session and never invalidated** beyond the local push in `createFormat`. A format created in another tab / session won't appear until reload. Same shape as the `GenreStore.allGenres` staleness gripe.

### Correctness bugs

- **Browse-by-format filters by name but receives a slug** (documented in `formats.md`). `BookController::index` does `where('formats.name', $format)`; the SPA passes `bookFormat.slug` from `BookTableRow` through `/formats/:format`. Works only for single-word formats whose slug matches the lowercased name. Multi-word formats ("Graphic Novel") return zero books. The dead `BookController::getBooksByFormat` method (unrouted) already implements the slug lookup correctly — either route to it or change the index branch to look up by slug.
- **`NewVersionsInput.validateVersion` checks `format?.name === 'audio'`** but the actual format is named `'Audiobook'`. The audio-runtime required-validation never fires. Either change the check to `'Audiobook'` (matching the template) or — better — replace the string match with a `format.format_id`-based lookup against a known constant.
- **Three different ways to identify "Audiobook"** across the codebase: `BookController::prepareVersions` matches `$format->name == 'Audiobook'`, `NewVersionsInput` matches `version.format?.name === 'Audiobook'` in the template (and `'audio'` in validation, see above), and `BookCreateEditForm` matches `format === 2` (hardcoded `format_id`). Renaming "Audiobook" or reseeding with a different `format_id` breaks one or more of these silently.
- **`FormatsList.vue` keys on `format.id`** but the field is `format_id`. Vue falls back to index-based keying with a console warning. Cosmetic today; will start mattering if the list ever supports reorder / delete.
- **Heterogeneous shape in `ConfigStore.books.formats`** after `createFormat`. The cached list mixes `{format_id, name}` (from `setFormats`) with full Eloquent rows (from `createFormat`). No consumer reads beyond `format_id` / `name`, so it works — but it is a real shape inconsistency that will bite the first consumer that reaches for `slug` or `created_at`.
- **`ConfigController::getFormats` projects via `Format::all()->map->only(...)`.** Loads every column then drops them in PHP rather than `Format::query()->select('format_id', 'name')->get()`. Trivial today; worth fixing the next time anyone touches the file.
- **Dead code in `BookController::getBooksByFormat`.** Not registered in `routes/api.php`, includes a `Log::info(... ->toSql())` debug line, and uses the un-imported short class name `Format::find($format->id)` (note: `id`, not `format_id` — would also have been broken). Either delete it or wire the route to it after fixing the column name.

### API surface

- **No GET / PATCH / DELETE endpoints.** Formats are write-once. There is no way to fix a typo (rename), no way to soft-delete an unused format, no way to merge duplicates. Mirrors the genres situation.
- **`/config/formats` and `/formats` live in different namespaces.** Bootstrap reads under `/config/`, writes under `/formats`. Not wrong — and the rationale (formats are config) is documented — but the asymmetry surprises new contributors. Once a real admin role exists, consider consolidating under `/admin/formats` (index + store + update + destroy) and keeping `/config/formats` as the read-only bootstrap projection.
- **No `FormatController::index`.** Admin-side listing reuses `ConfigController::getFormats`, which omits `slug` and timestamps — the admin UI cannot sort by recency or surface the slug. A real `FormatController::index` returning the full row would untangle this.

### Extensibility

- **No tests.** No coverage for `FormatController::store` (validation, slug derivation, unique-name check), `ConfigController::getFormats`, the `BookController::index` `?format=` filter (which has the slug-vs-name bug), or any of the three Audiobook-identification paths in version-handling.
- **No format metadata.** Just `name` + `slug`. There is no display order, no icon / color for the SPA, no "expects audio runtime" / "expects page count" capability flag — all of which the codebase currently fakes via hardcoded name / id checks. A `format_traits` JSON column or a small `format_capabilities` table would let `prepareVersions` and `NewVersionsInput` stop string-matching.
- **No format seeder.** Production / fresh dev databases depend on the admin surface being used to populate formats before any book can be created. A seeder for the canonical set ("Audiobook", "Paper", "Ebook") would make the dev loop friendlier and would lock in the `format_id` values that the hardcoded `=== 2` check in `BookCreateEditForm` already depends on.
- **`AdminActionView` dispatch is the only extensibility seam for new admin actions.** Documented in `formats.md` — register the component in the local `components` map and add a route with `meta: { component: '...' }`. Future admin actions should follow it; the second admin action is the moment to lift the map into a registry file (rule of three).

### Frontend & UX

- **Admin UI is functional-only.** No table, no sort, no slug column, no "X versions reference this format" count, no edit / delete affordances. `FormatsList.vue` is a `<ul>` of names.
- **Admin success / error messages don't clear.** `CreateFormat.vue` sets `success.value = true` and never clears it on the next keystroke; the green "Format created." banner persists while typing the next format name. Clear `success` and `error` on `name` watch.
- **`AdminHome.vue` is a single bullet link.** No nav, no breadcrumbs back from `AdminActionView`. Good enough for one action; needs a real shell once a second admin action exists.
- **`FormatView.vue` reuses the books-by-year pagination cleanup** (`cleanPaginationLinks`) and rewrites every page link to `/library?page=...` — i.e. the format browse view paginates *back to the library*, not back to the format. Probably a copy-paste bug; pagination beyond page 1 leaves the format context.
- **`FormatView.vue` has a leftover `console.log("RESPONSE", response.data)`** in the mounted hook. The `response` it logs is whatever `fetchData()` returns (which is `undefined`), so the log is doubly broken. Delete.
- **No "format detail" page.** `/formats/:slug` is just a bookshelf — no description, no version count, no top authors in the format. Same critique as `AuthorView` / `GenreView`.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Fix the slug-vs-name browse bug.** Either change `BookController::index`'s `?format=` branch to look up by slug (`whereHas('versions.format', fn ($q) => $q->where('formats.slug', $format))`) and delete the dead `BookController::getBooksByFormat` method, or wire the route to that method and fix its `format->id` → `format->format_id` typo. The slug-lookup approach is cleaner because it reuses the existing index filter shape. Add a Feature test covering "Graphic Novel" to lock the regression.
2. **Add Feature tests** for `FormatController::store` (validation, slug derivation, unique-name check, slug-collision case), `ConfigController::getFormats`, and the `BookController::index` `?format=` filter (post-fix). Necessary before any of the consolidation work below.
3. **Replace string / id matching with capability flags.** Add `expects_audio_runtime` / `expects_page_count` columns (or a small JSON `traits` blob) to `formats`. Backfill from the existing rows. Update `BookController::prepareVersions`, `NewVersionsInput`, and `BookCreateEditForm` to read the capability flag instead of matching `'Audiobook'` / `'Paper'` / `format_id === 2`. Removes three drift-prone string matches. Also fixes the `'audio'`-vs-`'Audiobook'` validation bug in `NewVersionsInput` as a side effect.
4. **Build a real admin role + `FormatPolicy`.** Add `is_admin` (or a roles table — see the auth doc when it lands) and gate `POST /formats` (and the future PATCH / DELETE). Add a router guard on `/admin/*`. Prerequisite for items 5 and 6.
5. **Build PATCH / DELETE for formats.** `PATCH /formats/{id}` for renames (re-derive slug, leave `format_id` intact so the frontend's hardcoded `=== 2` keeps working until item 3 lands). `DELETE /formats/{id}` only when no `Version` references the format — return 409 otherwise. Surface both in the admin UI as edit / delete buttons on `FormatsList`.
6. **Build a format-merge tool.** `POST /admin/formats/{keep_id}/merge/{remove_id}` re-points `versions.format_id` and deletes the loser. Required before any unique-slug index in item 7 can land cleanly. Mirrors the genre merge tool tracked in `/feature-plans/genres.md` item 5.
7. **Tighten the `formats` schema.** Make `slug` non-null, backfill from `Str::slug(name)` for any null rows, add a unique index on both `name` and `slug`. Will likely need item 6 to land first to merge any duplicates created by the un-validated history.
8. **Seed the canonical formats.** A `FormatSeeder` for "Audiobook", "Paper", "Ebook" with stable `format_id` values that the (item 3 notwithstanding) hardcoded checks rely on. Makes fresh-dev-DB and CI friendlier and locks the IDs that production depends on.
9. **Invalidate `ConfigStore.books.formats` after admin create.** Already done locally by `createFormat`'s `push`, but the resulting list is shape-heterogeneous. Switch `createFormat` to call `setFormats()` after a successful POST so the cache stays in `{format_id, name}` shape — or extend the `/config/formats` projection to include `slug` and align both shapes.
10. **Fix the `FormatView` pagination bug.** `cleanPaginationLinks` rewrites every link to `/library?page=...`; should be `/formats/${this.$route.params.format}?page=...`. Probably also worth lifting `cleanPaginationLinks` out of the view (it's duplicated in the year-browse views) into a small util.
11. **Drop the `console.log` in `FormatView.mounted`** and the dead `Log::info` debug line in `BookController::getBooksByFormat` (or delete the whole method per item 1).
12. **Build a real admin shell.** Sidebar nav, breadcrumb back to `AdminHome`, and the `AdminActionView` dispatch lifted into a small registry file once a second admin action exists. Coordinate with `/feature-plans/documentation-backfill.md` tier 4 (admin.md).
13. **Build a `FormatController::index`** that returns the full row (not the `/config/formats` projection) for use by the admin UI. Frees the admin list to surface `slug`, `created_at`, and a `versions_count`.
14. **Surface format-level stats on `FormatView`.** Total versions, total books, total reads in the format, average rating, top authors. Reuses the same aggregation logic that `StatisticsService` will end up with — coordinate with `/feature-plans/documentation-backfill.md` tier 3 (statistics) and the matching items in the genres / authors plans.
15. **Add display ordering.** A `display_order` column (or just sort by `format_id`) so the `<select>` is always presented in a deterministic, designer-controlled order rather than insertion order.
16. **Soft-delete formats** once item 5 lands, so an automatic prune (or an admin delete misclick) is recoverable. Same trait + `deleted_at` strategy as `/feature-plans/books.md` item 10 and the matching items in authors / genres.
17. **Stabilize `FormatsList` keying** — change `:key="format.id"` to `:key="format.format_id"`. One-line fix.
18. **Clear `success` / `error` on `CreateFormat` keystroke.** Watch `name`; reset both refs. One-line UX fix.
