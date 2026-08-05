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
- **Deleting a book deletes every account's reads of it, by design.** `BookController::destroy` lifts `BelongsToCurrentUser` deliberately (`withoutGlobalScope`) so no rows are orphaned against a deleted `book_id`. Correct, and it means one account can erase the other's read history through a book delete — which is the destructive-operation guard tracked in Future improvements item 11, now with a concrete blast radius.

### Performance & query shape

- **`BookController::index` joins `read_instances` without a user filter at the join level.** The returned read history is now scoped by `App\Models\Scopes\BelongsToCurrentUser` on the model, but a global scope constrains the model's own queries — it does not reach a raw `leftJoin` against its table, and the same is true in `searchBooks` and `GenreController::show`. The join inflates the grouped row count and forces `selectRaw` + manual `GROUP BY`; this will break on MySQL with `ONLY_FULL_GROUP_BY` (the default in MySQL 8) the moment any new column is added to the `select`.
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
- **`BookTableRow`'s `"Unknown"` fallbacks can never render.** `authorInfo()` and `bookFormat()` each return either an object (`{ name, slug }`) or the bare string `"Unknown"`, and the template only ever reaches for `.name` and `.slug` — which a string doesn't have. So a book with no authors renders a `router-link` to `params: { slug: undefined }` with an empty label: a broken link where the author's name belongs, and the word "Unknown" the fallback exists to show never appears. Same for a book with no versions on the desktop row's format link. The fix is to make the fallbacks return the right *shape* (`{ name: "Unknown", slug: null }`) so the existing `v-if="bookFormat.name"` guard starts doing its job, rather than to add string checks at each of the five call sites.
- **`BookTableRow::pageCount` is unguarded, directly below a guarded sibling.** It reads `this.book.versions[0].page_count`; `bookFormat()` five lines up checks `versions.length > 0` first. A book with zero versions throws `Cannot read properties of undefined` and takes the whole row's render with it — and "books with zero versions" is a state `/feature-plans/admin.md` item 14 already expects to find in the catalog.
- **`BookTableRow::primaryGenres` disagrees with itself three ways.** It's named "primary", its comment says "first 3 genres", and it slices `(0, 2)`. Decide which is intended; two of the three are wrong either way. See `/feature-plans/genres.md` for the UX side of how many genres a row should show.
- **Two computeds re-map a custom PK onto a generic `id`.** `primaryGenres` projects `genre_id` → `id`, and `BookView::readHistory` projects `read_instance_id` → `id`. The projections are internally correct and the pattern is defensible in isolation, but they create the only two places in the SPA where `:key="x.id"` is *right*, in a codebase whose one hard rule about primary keys is "never `id`". They are visually identical to the real `:key` bug that `FormatsList` carried for months, so every reader auditing for that bug class has to open the script block to clear three false positives. Either keep the custom key names in the projection, or leave a comment at each binding.

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
11. **Add a policy guarding destructive book operations.** Not ownership — the catalog is shared on purpose — but `destroy` and discard are the two operations where a second account can undo the other's work irrecoverably. Cheapest useful version is an audit trail plus a confirm-with-impact-summary; the confirm component now exists (`components/globals/ConfirmAction.vue`, see `/documentation/admin.md`), the audit trail is `/feature-plans/admin.md` item 3.
12. **Decide whether "on loan" and "wishlist" join "discarded" as version states.** If a second ownership state lands, `is_discarded` should generalize to a single `ownership_state` enum column (with `state_changed_at`) rather than accumulating parallel booleans. Doing it now would be speculative; doing it on the second state is the right moment.
13. **Make the book-view computeds return one type each.** `BookTableRow::authorInfo` / `::bookFormat` (object-or-`"Unknown"`), `BookView::readHistory` (array-or-`""`), plus a guard on `BookTableRow::pageCount`. All four are logged under Known limitations above and in `/feature-plans/read-history.md`; they're one small separable commit, and fixing the shapes is what makes the `v-if` guards already sitting in those templates start working. Worth pairing with a component-testing setup (`/feature-plans/frontend-tests.md`) — every one of these is invisible to the current suite, which is why they survived this long.
