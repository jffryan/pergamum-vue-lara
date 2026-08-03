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
- **`addReadInstance` does no validation** of `date_read` format. (`rating` range is now checked via `RatingValidator`, and the version-belongs-to-book invariant is enforced at the model layer — see Data integrity.)
- **`prepareVersions` silently drops versions with an unknown `format_id`** (`continue` on `!$format`). The caller gets a 200 with fewer versions than it submitted and no indication which were skipped.

### Data integrity

- **`ReadInstance` dual-attach is enforced model-side.** `book_id` and `version_id` are both stored; a `saving` listener on the model throws `\DomainException` when `version_id`'s `Version.book_id` doesn't match the row's `book_id`, so no future code path can produce a row queryable from one side and not the other. `addReadInstance` short-circuits with a `422 { reason_code: 'version_book_mismatch' }` to keep the failure structured for callers. Dropping `book_id` from `read_instances` entirely (deriving through the version relation) is still on the table — see Future improvements.
- **`destroy` hard-deletes with cascading author pruning.** No soft deletes anywhere in the domain. Deleting a book irreversibly removes its versions, read instances, and any author left with zero books — no undo, no audit trail. A misclick on the wrong book wipes years of read history.
- **`rating` mutator doubles on write with no inverse accessor.** Every consumer must remember to halve when displaying. Bulk inserts via the query builder bypass the mutator and store the un-doubled value, which then displays at half the intended rating.
- ~~**`ReadInstance::$primaryKey` is `read_instances_id`, but the migration column is `read_instance_id`.**~~ Fixed — the model now declares `read_instance_id`, and `BookController::updateReadInstances` plus the edit form read the same key. The note that "no caller has tried to look one up by primary key" turned out to be wrong: `PUT /api/books/{id}` did, which is why editing a rating or date from the book edit page silently did nothing. `tests/Feature/Books/UpdateReadInstancesTest.php` pins it.
- **`Book::$fillable` lists `date_completed` but no `books.date_completed` column exists.** The `getDateCompletedAttribute` accessor formats a value that is never persisted — it only ever returns `null`. Either add the column + a migration to backfill it from the latest `read_instances.date_read`, or drop the fillable entry and the accessor. Today this is dead code that looks live.
- ~~**`prepareVersions` reads `$version_data['audio_runtime']` without an `isset` guard for any non-`Paper` format.**~~ Fixed by the format capability flags — `BookController::lengthFieldsFor` coalesces every expected field and nulls every unexpected one. Worth recording *why* it was so easy to hit: the `'Paper'` branch had never matched anything, because the format is named **Physical**, so every non-audio format fell into the audio branch. Pinned by `tests/Feature/Books/VersionLengthFieldsTest.php`.
- **Discarding a copy is not audited and not user-scoped.** `PATCH /versions/{id}/discard` is gated only by `auth:sanctum`, like the rest of the catalog — any authenticated user can discard any copy, and there is no record of who did it or when the flag was flipped (`discarded_at` is the user-supplied real-world date, not a system timestamp; `updated_at` is the closest thing and gets clobbered by unrelated edits). Fine while the app is effectively single-tenant; becomes a real gap alongside the `BookPolicy` / multi-tenant work in Future improvements item 3.
- **Discarded copies still count toward statistics and lists.** `StatisticsService` aggregates over `read_instances`, which is intentional — you read the book, discarding it later doesn't undo that. But `ListItem` references `version_id` with no discard awareness, so a list can point at a copy you no longer own and nothing surfaces that. `ListItemsTable` doesn't render the state either.
- ~~**`versions.page_count` is `NOT NULL` in MySQL but treated as optional by callers.**~~ Fixed — the column is nullable as of `2026_08_02_000002`, and a format that declares `expects_page_count = false` stores null rather than the `0` the importer used to invent. Both read as zero to every `SUM`, so no statistic changed.

### Performance & query shape

- **`BookController::index` joins `read_instances` without a user filter at the join level.** The user filter only exists in the eager-load constraint for the relationship. The join inflates the grouped row count and forces `selectRaw` + manual `GROUP BY`; this will break on MySQL with `ONLY_FULL_GROUP_BY` (the default in MySQL 8) the moment any new column is added to the `select`.
- ~~**`searchBooks` has unsafe `orWhere` precedence.**~~ Fixed when the discarded-copies filter landed — the title/author OR terms are now wrapped in `where(function ($q) {…})`, so appended `AND` constraints bind to the whole group. Pinned by `tests/Feature/Books/DiscardedBooksFilterTest::test_search_obeys_the_same_shelf_filter`. Keep the wrapper when adding search terms.
- **LIKE wildcards in user input are not escaped.** `%` and `_` in a search term match arbitrarily. SQL-injection-safe (it's bound), but UX-broken.
- **`getCompletedItemsForYear` fetches every book for the year and sorts in PHP.** No pagination, no DB-side ordering on the outer query. Will degrade as read history grows.
- **`getBookWithRelations` runs an unordered `limit(3)` on related-by-author books.** Result set is non-deterministic across requests and can flicker on the detail page.

### API surface

- **Book *creation* still lives on two controllers** (`BookController::store` and `NewBookController::completeBookCreation`), but they now share a single creator (`App\Support\BookCreator`) and apply the same suffix-on-collision strategy. The `wasRecentlyCreated` "add a version to an existing book" short-circuit on `store` was removed; that flow belongs on `POST /versions`. The two endpoints still differ in request shape and in how they handle authors/genres/versions/read-instances — consolidating to a single endpoint is still desirable but no longer urgent.
- **`BookController::update` silently drops new read instances.** The edit-form payload may include a new `readInstance` row with no `read_instance_id`; the controller filters those out before calling `updateReadInstances`. The UI has no affordance for this either way, so the behavior is invisible — but if the form is ever wired to send new entries, they vanish. (Until the primary-key fix above, the filter dropped *every* row, new or not, so edits to existing reads vanished too.)
- **`update` returns raw exception messages in 500 responses.** Leaks internals (table names, SQL fragments) to the client.
- **`BookController::getBooksByFormat` is dead code.** Not in `routes/api.php`, and uses `$format->id` instead of `$format->format_id`, so it would 500 immediately if called. Worth deleting.
- **`POST /books` accepting "create or add-version" by slug match is undocumented** from the route shape. Callers reading `routes/api.php` would not guess this branch exists.

### Extensibility

- ~~**`prepareVersions` branches on `$format->name == 'Audiobook'` / `'Paper'`.**~~ Done — see Future improvements item 6. The format row is the config; `BookController::lengthFieldsFor` iterates what it declares.
- **Author slug normalization now routes through `App\Support\Slugger::for()`** in `BookController::updateAuthors`, `BookController::handleAuthors`, and `NewBookController::handleAuthors` (joining `first_name` + `last_name` before slugifying). Legacy rows have been backfilled and `authors.slug` is uniquely indexed at the DB level (see `2026_04_30_000000_make_authors_slug_unique_and_required.php`).
- **No tests for the books feature.** `tests/Feature` and `tests/Unit` do not cover book create / update / destroy / read-instance paths. Any of the refactors below will be flying blind without first adding coverage.

### Frontend

- **`BookServices.js` is the only file in `resources/js/services/`.** The "service orchestrates stores" pattern that CLAUDE.md describes exists for books and nothing else; the convention isn't really established yet, just claimed.
- **No optimistic updates on read-instance creation.** UI waits for the round trip; on slow connections the rating widget feels laggy.
- **Discarding is only reachable from the book detail page.** `VersionTableRow` owns the affordance, so there is no way to discard from the library table, from a list, or in bulk — which is awkward for the exact case that motivated the feature (a large backlog of copies got rid of years ago). A bulk "mark these as discarded" surface, and a `discarded` column in the bulk-upload CSV contract, are the obvious follow-ups.
- **The discarded shelf reuses `LibraryView` wholesale.** `/library?discarded=only` renders the same table with the same heading logic and no discard-specific columns (no discard date, no "which copies"). Fine for now; a dedicated view would be the moment to surface `discarded_at`.
- **`VersionTable`'s header spans are built by string interpolation** (`col-span-${column.span}`), which Tailwind's scanner cannot see. It only works because `col-span-2` and `col-span-3` happen to appear literally in `VersionTableRow`. Adding a column with any other span will silently render unstyled — safelist the classes or stop interpolating.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Add Feature tests** for create, update, destroy, add-read-instance, and the slug-collision and dual-attach edge cases. Everything else below is risky without these.
2. **Introduce `FormRequest` classes** (`StoreBookRequest`, `UpdateBookRequest`, `StoreReadInstanceRequest`, `StoreVersionRequest`). Centralize validation, fail loud, return 422 instead of 500. Flatten the `request.formData` nesting on `update` while you're there.
3. **Add `BookPolicy` and a multi-tenant ownership model** — likely a `user_id` (or `owned_by`) on `books`, `versions`, `authors`, `genres`, with the existing `auth:sanctum` middleware enforcing it. Decide first whether authors/genres are per-user or shared; a shared catalog with per-user reading state is probably the right shape.
4. **Consolidate create endpoints.** Both `POST /books` and `POST /create-book` now share `App\Support\BookCreator` for the book row itself, but they still own divergent request shapes and divergent author/genre/version/read-instance handling. Pick one endpoint to own creation end-to-end and migrate the legacy `BookCreateEditForm` create path off the other.
6. ~~**Refactor `prepareVersions` to a format-driven dispatch.**~~ Done, and more cheaply than this plan assumed: no `FormatHandler` registry was needed, because "what fields apply" is data, not behavior. Two boolean columns on `formats` (`expects_page_count`, `expects_audio_runtime`) are the whole config, `Format::expectedLengthFields()` exposes them keyed by column, and `BookController::lengthFieldsFor` iterates rather than branches — so create and edit share one rule instead of two. A format with genuinely different *behavior* (rather than a different set of length fields) would still want the registry; nothing on the roadmap needs one. See `/documentation/formats.md`.
7. **Add a `rating` accessor that halves on read**, or — better — store ratings in their natural units and migrate existing data. The mutator-without-accessor asymmetry is a recurring source of bugs.
8. **Drop `book_id` from `read_instances` entirely** and derive it through the version relation. The model-level cross-table validator is now in place, but the dual-attach itself remains a holdover with no clear benefit.
9. **Soft-delete books, versions, and read instances** (`SoftDeletes` trait + `deleted_at` columns). Keep the orphaned-author pruning but make it recoverable. The current hard-cascade-on-destroy is the single most user-hostile behavior in the app.
10. **Fix the `index` query.** Either filter `read_instances` at the join (`AND read_instances.user_id = ?`) or drop the join and rely on the eager-load. Drop the manual `GROUP BY` in favor of a subquery for the primary-author-last-name sort. (The search OR-clause wrapper is done.)
11. ~~**Give the discard filter a scope rather than a private controller method.**~~ Done — `Book::scopeOnShelf()` / `Book::scopeFullyDiscarded()` now hold the "every version discarded" rule, and `BookController::applyDiscardedFilter` only maps the query string onto them. The `newestBooks` statistics metric was the third caller that triggered the move.

    **The trigger has now fired.** `/feature-plans/statistics-widgets.md` shelf-scopes its `newestBooks` metric, which is the third caller and the first one outside `BookController`. That plan owns the extraction as its Phase 0 and repoints `applyDiscardedFilter` at the new scopes; close this item out when it lands rather than doing it twice.
12. **Decide whether "on loan" and "wishlist" join "discarded" as version states.** If a second ownership state lands, `is_discarded` should generalize to a single `ownership_state` enum column (with `state_changed_at`) rather than accumulating parallel booleans. Doing it now would be speculative; doing it on the second state is the right moment.
11. **Escape LIKE wildcards** in `searchBooks` (`addcslashes($search, '%_\\')`).
12. **Paginate `getCompletedItemsForYear`** and move sorting to the DB.
13. **Stabilize `authorRelatedBooks` ordering** in `BookService::getBookWithRelations` — order by `book_id` or `title` and dedupe by book.
14. **Delete `BookController::getBooksByFormat`** (dead and broken).
15. **Stop returning raw exception messages in 500 responses.** Log them, return a generic message.
16. **Audit `addReadInstance` to do a single save** (currently saves through both book and version, which Eloquent dedupes but is confusing).
17. **Optimistic UI for read-instance create** in `BooksStore` — push the row into local state immediately, reconcile on response.
18. ~~**Align `ReadInstance::$primaryKey` with the migration**~~ — done. Route-model binding and a future `PATCH`/`DELETE` on read instances are now unblocked.
19. **Resolve the `Book::date_completed` mismatch** — either add the column or drop the fillable + accessor. Whichever direction, do it before the books refactor in items 2–4 so callers don't accidentally start writing it.
