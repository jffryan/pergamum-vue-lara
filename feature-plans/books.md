---
path: /feature-plans/
status: living
---

# Books

Tracks rough edges and follow-up work for the Books domain (Book / Version / ReadInstance). Descriptive content lives in `/documentation/books.md`.

## Known limitations

### Authorization

- **No per-user ownership of books, versions, authors, or genres.** Only `ReadInstance` carries `user_id`. Any authenticated user can edit or destroy any book, add versions to it, attach authors/genres, and trigger the orphaned-author cascade in `destroy`. The app has been single-tenant in practice, which is the only reason this hasn't bitten — but every write endpoint on `BookController`, `VersionController`, and `NewBookController` is currently a multi-tenant footgun.
- **`BookListPolicy` is the only policy in the app.** Books have no policy at all; controllers do not call `authorize()`.

### Validation & request shape

- **No `FormRequest` classes anywhere in the books flow.** Controllers reach into `$request->book`, `$request['request']['formData']`, `$request['readInstance']` directly. Missing keys throw undefined-index errors that surface as 500s.
- **`update` expects a doubly-nested payload** (`request.formData.book`, `request.formData.authors`, …). This is an artifact of how the frontend sends edit forms; it should be flattened on both ends.
- **`addReadInstance` does no validation** of `date_read` format, `rating` range, or that `version_id` actually belongs to `book_id`. A read instance can be created pointing at a version of a different book.
- **`prepareVersions` silently drops versions with an unknown `format_id`** (`continue` on `!$format`). The caller gets a 200 with fewer versions than it submitted and no indication which were skipped.

### Data integrity

- **Slug collisions collapse distinct books.** `createOrGetBook` normalizes title → slug (lowercase, strip non-alphanumerics, 50-char cap) and treats a slug match as "same book." Two books with titles that normalize identically (e.g. a series where every entry truncates to the same 50 chars) become one record. There is no uniqueness suffix and no DB-level unique index on `books.slug`.
- **`ReadInstance` is dual-attached but not enforced.** `book_id` and `version_id` are both stored, but nothing guarantees the version actually belongs to the book. A bad write leaves the row queryable from one side and not the other. No DB constraint, no model-level validation.
- **`destroy` hard-deletes with cascading author pruning.** No soft deletes anywhere in the domain. Deleting a book irreversibly removes its versions, read instances, and any author left with zero books — no undo, no audit trail. A misclick on the wrong book wipes years of read history.
- **`rating` mutator doubles on write with no inverse accessor.** Every consumer must remember to halve when displaying. Bulk inserts via the query builder bypass the mutator and store the un-doubled value, which then displays at half the intended rating.
- **`ReadInstance::$primaryKey` is `read_instances_id`, but the migration column is `read_instance_id`.** Anything that calls `$readInstance->getKey()`, `find()`, `findOrFail()`, or relies on route-model binding will hit a column that doesn't exist. The model works today only because no caller has tried to look one up by primary key — `addReadInstance` saves through a relation, and there are no edit/delete endpoints (see `/feature-plans/read-history.md`). Fix by aligning the model to the migration (`read_instance_id`); a rename migration is not needed.
- **`Book::$fillable` lists `date_completed` but no `books.date_completed` column exists.** The `getDateCompletedAttribute` accessor formats a value that is never persisted — it only ever returns `null`. Either add the column + a migration to backfill it from the latest `read_instances.date_read`, or drop the fillable entry and the accessor. Today this is dead code that looks live.
- **`prepareVersions` reads `$version_data['audio_runtime']` without an `isset` guard for any non-`Paper` format.** Surfaced while writing `tests/Feature/Books/BooksCrudTest.php` — a Hardcover/Ebook payload that omits `audio_runtime` 500s with `Undefined array key`. Tests now send `audio_runtime` (null or numeric) on every version regardless of format to work around it. Real bug; controller should default the key, or `prepareVersions` should `?? null`.
- **`versions.page_count` is `NOT NULL` in MySQL but treated as optional by callers.** Surfaced from the same test run — sending `page_count: null` for an audiobook (where the model logically allows null) blows up with an integrity-constraint violation. Tests work around it by sending `0`. Real bug; the migration column should be nullable, or `prepareVersions` should coerce missing/null page counts to `0` before insert.

### Performance & query shape

- **`BookController::index` joins `read_instances` without a user filter at the join level.** The user filter only exists in the eager-load constraint for the relationship. The join inflates the grouped row count and forces `selectRaw` + manual `GROUP BY`; this will break on MySQL with `ONLY_FULL_GROUP_BY` (the default in MySQL 8) the moment any new column is added to the `select`.
- **`searchBooks` has unsafe `orWhere` precedence.** The title/author OR is appended after the joins without a `where(function($q){…})` wrapper, so adding any future scoped filter (active users, soft-deleted, format) will combine via top-level OR and ignore the constraint.
- **LIKE wildcards in user input are not escaped.** `%` and `_` in a search term match arbitrarily. SQL-injection-safe (it's bound), but UX-broken.
- **`getCompletedItemsForYear` fetches every book for the year and sorts in PHP.** No pagination, no DB-side ordering on the outer query. Will degrade as read history grows.
- **`getBookWithRelations` runs an unordered `limit(3)` on related-by-author books.** Result set is non-deterministic across requests and can flicker on the detail page.

### API surface

- **Book *creation* lives on `NewBookController` but `BookController::store` ALSO creates books** — and the two paths handle authors/versions/genres differently. `store` doubles as "add a version to an existing book" via a `wasRecentlyCreated` short-circuit. The two endpoints should be reconciled; one or the other should own creation.
- **`BookController::update` silently drops new read instances.** The edit-form payload may include a new `readInstance` row with no `read_instances_id`; the controller filters those out before calling `updateReadInstances`. The UI has no affordance for this either way, so the behavior is invisible — but if the form is ever wired to send new entries, they vanish.
- **`update` returns raw exception messages in 500 responses.** Leaks internals (table names, SQL fragments) to the client.
- **`BookController::getBooksByFormat` is dead code.** Not in `routes/api.php`, and uses `$format->id` instead of `$format->format_id`, so it would 500 immediately if called. Worth deleting.
- **`POST /books` accepting "create or add-version" by slug match is undocumented** from the route shape. Callers reading `routes/api.php` would not guess this branch exists.

### Extensibility

- **`prepareVersions` branches on `$format->name == 'Audiobook'` / `'Paper'`.** Adding a new format with different field semantics requires editing this method. CLAUDE.md calls out exactly this kind of seam ("another book status, another list type") as the place to use config-driven dispatch — the books flow predates that guidance and has not been refactored.
- **Slug normalization is duplicated** in `BookController::createOrGetBook`, `BookController::updateBook`, `BookController::updateAuthors`, and `BookController::handleAuthors`. Four near-identical regex chains; one drift away from a bug.
- **No tests for the books feature.** `tests/Feature` and `tests/Unit` do not cover book create / update / destroy / read-instance paths. Any of the refactors below will be flying blind without first adding coverage.

### Frontend

- **`BookServices.js` is the only file in `resources/js/services/`.** The "service orchestrates stores" pattern that CLAUDE.md describes exists for books and nothing else; the convention isn't really established yet, just claimed.
- **No optimistic updates on read-instance creation.** UI waits for the round trip; on slow connections the rating widget feels laggy.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Add Feature tests** for create, update, destroy, add-read-instance, and the slug-collision and dual-attach edge cases. Everything else below is risky without these.
2. **Introduce `FormRequest` classes** (`StoreBookRequest`, `UpdateBookRequest`, `StoreReadInstanceRequest`, `StoreVersionRequest`). Centralize validation, fail loud, return 422 instead of 500. Flatten the `request.formData` nesting on `update` while you're there.
3. **Add `BookPolicy` and a multi-tenant ownership model** — likely a `user_id` (or `owned_by`) on `books`, `versions`, `authors`, `genres`, with the existing `auth:sanctum` middleware enforcing it. Decide first whether authors/genres are per-user or shared; a shared catalog with per-user reading state is probably the right shape.
4. **Reconcile create paths.** Either delete `BookController::store`'s creation branch and route everything through `NewBookController`, or vice versa. Move "add version to existing book" to `POST /versions` exclusively.
5. **Unique index on `books.slug`** + a collision suffix in the slug generator (`-2`, `-3`, …). Migrate existing duplicates first (there may be some lurking). **Prerequisite for the database restore work in `/feature-plans/reset-database.md`** — needs to land between the bulk-upload hardening (`/feature-plans/bulk-upload-hardening.md`) and the actual restore run, so the importer's find-or-create-by-slug logic is backstopped by a DB constraint before a 1000-row import relies on it.
6. **Extract slug generation to a single helper** (e.g. `App\Support\Slugger::for($title)`) and use it everywhere. Same for author slug.
7. **Refactor `prepareVersions` to a format-driven dispatch.** A `FormatHandler` registry keyed by format slug, each implementing "what fields apply, what defaults to set." This is the seam CLAUDE.md asks for and is the right place to demonstrate the pattern.
8. **Add a `rating` accessor that halves on read**, or — better — store ratings in their natural units and migrate existing data. The mutator-without-accessor asymmetry is a recurring source of bugs.
9. **Add a DB-level CHECK or model-level validator** that `read_instances.version_id` belongs to `read_instances.book_id`. Or drop `book_id` from `read_instances` entirely and derive it through the version relation; the dual-attach is a holdover with no clear benefit.
10. **Soft-delete books, versions, and read instances** (`SoftDeletes` trait + `deleted_at` columns). Keep the orphaned-author pruning but make it recoverable. The current hard-cascade-on-destroy is the single most user-hostile behavior in the app.
11. **Fix the `index` query.** Either filter `read_instances` at the join (`AND read_instances.user_id = ?`) or drop the join and rely on the eager-load. Drop the manual `GROUP BY` in favor of a subquery for the primary-author-last-name sort. Wrap search OR clauses in a closure.
12. **Escape LIKE wildcards** in `searchBooks` (`addcslashes($search, '%_\\')`).
13. **Paginate `getCompletedItemsForYear`** and move sorting to the DB.
14. **Stabilize `authorRelatedBooks` ordering** in `BookService::getBookWithRelations` — order by `book_id` or `title` and dedupe by book.
15. **Delete `BookController::getBooksByFormat`** (dead and broken).
16. **Stop returning raw exception messages in 500 responses.** Log them, return a generic message.
17. **Audit `addReadInstance` to do a single save** (currently saves through both book and version, which Eloquent dedupes but is confusing).
18. **Optimistic UI for read-instance create** in `BooksStore` — push the row into local state immediately, reconcile on response.
19. **Align `ReadInstance::$primaryKey` with the migration** (`read_instance_id`). Cheap fix; unblocks any future edit/delete endpoint and route-model binding.
20. **Resolve the `Book::date_completed` mismatch** — either add the column or drop the fillable + accessor. Whichever direction, do it before the books refactor in items 2–4 so callers don't accidentally start writing it.
