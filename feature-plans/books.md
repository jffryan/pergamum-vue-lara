---
path: /feature-plans/
status: living
---

# Books

Tracks rough edges and follow-up work for the Books domain (Book / Version / ReadInstance). Descriptive content lives in `/documentation/books.md`.

## Known limitations

### Authorization

- **Books have no policy.** `BookListPolicy` is still the only one in the app; `BookController` never calls `authorize()`. Note the catalog being shared between accounts is deliberate — see `/documentation/books.md` — so what's missing here is not ownership but a guard on the *destructive* operations.
- **Discarding a copy is not audited.** `PATCH /versions/{id}/discard` records no actor and no system timestamp for the flip. `discarded_at` is the user-supplied real-world date, not a system one; `updated_at` gets clobbered by unrelated edits. With a second account this becomes "who got rid of this and when".

### Data integrity

- **`destroy` hard-deletes with cascading author pruning.** No soft deletes anywhere in the domain. Deleting a book irreversibly removes its versions, read instances, and any author left with zero books — no undo, no audit trail. A misclick wipes years of read history, for both accounts.
- **`rating` mutator doubles on write with no inverse accessor.** Every consumer must remember to halve when displaying. Bulk inserts via the query builder bypass the mutator and store the un-doubled value, which then displays at half the intended rating.
- **`read_instances` still carries `book_id` alongside `version_id`.** The invariant is enforced model-side, but the dual-attach is a holdover — see Future improvements.
- **Discarded copies still count toward statistics and lists.** Aggregating reads over discarded copies is intentional — you read the book, discarding it later doesn't undo that. But `ListItem` references `version_id` with no discard awareness, so a list can point at a copy you no longer own and nothing surfaces that. `ListItemsTable` doesn't render the state either.

### Performance & query shape

- **`BookController::index` joins `read_instances` without a user filter at the join level.** The user filter only exists in the eager-load constraint. The join inflates the grouped row count and forces `selectRaw` + manual `GROUP BY`; this will break on MySQL with `ONLY_FULL_GROUP_BY` (the default in MySQL 8) the moment any new column is added to the `select`.
- **LIKE wildcards in user input are not escaped.** `%` and `_` in a search term match arbitrarily. SQL-injection-safe (it's bound), but UX-broken.
- **`getCompletedItemsForYear` fetches every book for the year and sorts in PHP.** No pagination, no DB-side ordering on the outer query. Will degrade as read history grows.
- **`getBookWithRelations` runs an unordered `limit(3)` on related-by-author books.** Result set is non-deterministic across requests and can flicker on the detail page.

### API surface

- **Book creation still lives on two controllers** (`BookController::store` and `NewBookController::completeBookCreation`). They share `App\Support\BookCreator` for the book row and the same collision strategy, but still own divergent request shapes and divergent author/genre/version/read-instance handling.
- **`BookController::update` silently drops new read instances.** Rows with no `read_instance_id` are filtered out before the update runs. The UI has no affordance for adding one from the edit view, so this is invisible today — but if the form is ever wired to send new entries, they vanish.
- **`BookController::getBooksByFormat` is dead code.** Not in `routes/api.php`, and uses `$format->id` instead of `$format->format_id`, so it would 500 if called.
- **`POST /books` accepting "create or add-version" by slug match is undocumented** from the route shape. Callers reading `routes/api.php` would not guess this branch exists.

### Frontend

- **`BookServices.js` is the only file in `resources/js/services/`.** The "service orchestrates stores" pattern that CLAUDE.md describes exists for books and nothing else; the convention isn't really established yet, just claimed.
- **No optimistic updates on read-instance creation.** UI waits for the round trip; on slow connections the rating widget feels laggy.
- **Discarding is only reachable from the book detail page.** `VersionTableRow` owns the affordance, so there is no way to discard from the library table, from a list, or in bulk — awkward for the exact case that motivated the feature (a backlog of copies got rid of years ago). A bulk "mark these as discarded" surface, and a `discarded` column in the bulk-upload CSV contract, are the obvious follow-ups.
- **The discarded shelf reuses `LibraryView` wholesale.** `/library?discarded=only` renders the same table with no discard-specific columns (no discard date, no "which copies"). A dedicated view would be the moment to surface `discarded_at`.
- **`VersionTable`'s header spans are built by string interpolation** (`col-span-${column.span}`), which Tailwind's scanner cannot see. It only works because `col-span-2` and `col-span-3` happen to appear literally in `VersionTableRow`. Adding a column with any other span will silently render unstyled — safelist the classes or stop interpolating.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Soft-delete books, versions, and read instances** (`SoftDeletes` trait + `deleted_at` columns). Keep the orphaned-author pruning but make it recoverable. The current hard-cascade-on-destroy is the single most user-hostile behavior in the app, and the matching items in the lists, authors, genres and formats plans should land as one piece of work.
2. **Consolidate create endpoints.** Pick one of `POST /books` / `POST /create-book` to own creation end-to-end and migrate the legacy `BookCreateEditForm` create path off the other. The two request shapes are now described by `StoreBookRequest` and `CompleteBookCreationRequest`, which makes the divergence explicit and the merge mechanical.
3. **Add a `rating` accessor that halves on read**, or — better — store ratings in their natural units and migrate existing data. The mutator-without-accessor asymmetry is a recurring source of bugs.
4. **Drop `book_id` from `read_instances`** and derive it through the version relation. The model-level cross-table validator is in place, but the dual-attach itself has no clear benefit.
5. **Fix the `index` query.** Either filter `read_instances` at the join (`AND read_instances.user_id = ?`) or drop the join and rely on the eager-load. Drop the manual `GROUP BY` in favor of a subquery for the primary-author-last-name sort.
6. **Escape LIKE wildcards** in `searchBooks` (`addcslashes($search, '%_\\')`).
7. **Paginate `getCompletedItemsForYear`** and move sorting to the DB.
8. **Stabilize `authorRelatedBooks` ordering** in `BookService::getBookWithRelations` — order by `book_id` or `title` and dedupe by book.
9. **Delete `BookController::getBooksByFormat`** (dead and broken). Coordinate with `/feature-plans/formats.md` item 1, which decides whether the `?format=` filter moves to it or absorbs it.
10. **Optimistic UI for read-instance create** in `BooksStore` — push the row into local state immediately, reconcile on response.
11. **Add a policy guarding destructive book operations.** Not ownership — the catalog is shared on purpose — but `destroy` and discard are the two operations where a second account can undo the other's work irrecoverably. Cheapest useful version is an audit trail plus a confirm-with-impact-summary; see `/feature-plans/admin.md` items 3 and 25.
12. **Decide whether "on loan" and "wishlist" join "discarded" as version states.** If a second ownership state lands, `is_discarded` should generalize to a single `ownership_state` enum column (with `state_changed_at`) rather than accumulating parallel booleans. Doing it now would be speculative; doing it on the second state is the right moment.
