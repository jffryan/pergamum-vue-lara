---
path: /feature-plans/
status: living
---

# Authors

Tracks rough edges and follow-up work for the Authors taxonomy. Descriptive content lives in `/documentation/authors.md`.

Most of the gnarly behavior here is owned by the book pipeline (attach on create/update, prune on delete). That work is tracked in `/feature-plans/books.md`; items below are author-specific or cross-cut both files.

## Known limitations

### Authorization & ownership

- **No per-user ownership.** Authors are global — any authenticated user can cause an author row to be created (via book create) or pruned (via the orphan-cascade in `BookController::destroy`). Two users adding the same author at the same time will collide on whichever one's slug-normalizer ran first. Tracked under the broader multi-tenant gap in `/feature-plans/books.md` item 3.
- **No `AuthorPolicy`.** The five `Route::resource`-style stub methods on `AuthorController` (`index`, `create`, `store`, `edit`, `update`, `destroy`) are unreachable, so this hasn't mattered yet. The moment any of them gets implemented, a policy needs to land with it.

### Validation & request shape

- **No `FormRequest` classes.** `getOrSetToBeCreatedAuthorsByName` reaches into `$request['authorsData']` and each entry's `name` / `first_name` / `last_name` directly. Missing keys throw undefined-index 500s. Same pattern as the books flow — same fix applies.
- **`/create-authors` does no validation** on name length, character set, or duplicate entries within a single request. Submitting `[{name: ''}, {name: ''}]` happily produces two stubs with empty slugs.

### Data integrity

- **`authors.slug` is nullable and has no unique index.** `firstOrCreate` is the only dedupe, so a race or a divergent normalizer can produce duplicates. The migration also allows `slug = null`, which means "the authors with no slug" all collide on `firstOrCreate(['slug' => null, …])` lookups.
- **Three divergent slug normalizers** (documented in `authors.md`):
  - `AuthorController::getOrSetToBeCreatedAuthorsByName` — strips non-alphanumerics, **30-char cap**.
  - `BookController::handleAuthors` — keeps non-alphanumerics, no cap.
  - `NewBookController::handleAuthors` — strips non-alphanumerics, no cap.
  Result: an author created via book-edit and later re-submitted via `/create-authors` can fail to match itself. Existing data may already have duplicates from this drift.
- **No author merge tool.** Once duplicates exist (from divergent slugs, typos, "Jr." vs "Jr", etc.), there's no API or UI to combine them — the only fix is manual SQL.
- **Orphan-pruning is silent and irreversible.** When `BookController::destroy` deletes the last book by an author, the author row is hard-deleted with no audit trail. If the book deletion was a misclick, the author has to be re-typed by hand and gets a fresh `author_id`, breaking any external reference. Linked from `/feature-plans/books.md` item 10 (soft deletes).
- **`bio` is returned by the API but has no column.** `AuthorService::getAuthorWithRelations` includes `bio` in the response payload; the migration doesn't define it and the model doesn't declare it. Reads as `null` today; if a frontend ever depends on it before the column exists, it'll break silently.

### Performance & query shape

- **`AuthorService::getAuthorWithRelations` is one big eager-load with no pagination.** A prolific author with hundreds of books pulls every book, every version, every read instance, every genre, every author of every related book in a single query tree. Fine today; will not be fine at scale.
- **`books.readInstances` is not user-scoped on the author page** (called out in `authors.md`). Multi-tenant deployment would leak read history across users. In the single-tenant case it's "merely" pulling other-user reads that the UI ignores, which is wasted bandwidth.

### API surface

- **`/create-authors` is unused.** Defined and reachable, but the live new-book flow handles authors inline through `POST /create-book`. Either delete the endpoint or rewire the frontend to use it (the latter is the point of the find-or-stub pattern — let the UI confirm matches before committing). Currently the worst of both: the contract exists, isn't enforced, and can drift from the inline path.
- **No `GET /authors` index endpoint.** The five `Route::resource` stubs would naturally cover this, but they're empty. There's no way to list authors short of fetching every book.
- **`AuthorController::show` and `getAuthorBySlug` are duplicates.** Both delegate to `AuthorService::getAuthorWithRelations($slug, 'slug')`. `show` isn't routed (no `Route::resource('authors', …)`) so it's dead, but the duplication is a footgun if someone later wires the resource route — they'll get two ways to do the same thing.

### Extensibility

- **No tests.** No coverage for the slug normalizers, find-or-stub, the author detail payload, or the orphan-prune path on book delete.
- **`AuthorsStore` is mostly stub.** `allAuthors` and `sortedBy` exist but are never read or written; only `currentAuthor` is wired. Not a bug, but anyone extending the store will assume infrastructure exists that doesn't.

### Frontend & UX

- **No author index / browse view.** Users can land on an author page only by clicking through from a book card or table row. There's no `/authors` listing, no alphabetic browse, no search.
- **`getOneAuthor` in `api/AuthorController.js` is dead code.** Builds `/api/authors/{id}`, which 404s. Delete it.
- **Only the primary author is linked from book rows.** `BookTableRow` and `ListItemsTable` both hardcode `book.authors[0]`. Multi-author books surface only one name on the table; the others are reachable only from the book detail page.
- **Author detail page is bookshelf-only.** Reuses `BookshelfTable` and shows nothing about the author themselves — no bio (no column), no photo, no aggregated stats (total books read, average rating across their catalog, first/most-recent read). The page header is just `"{first} {last}"`.
- **No edit affordance for authors.** Fixing a typo in `first_name` requires opening every book by that author and editing through the book edit flow. The "edit author" UI doesn't exist.
- **Author sort on book lists is by primary author's last name only.** A book by "Smith & Adams" sorts under whichever name is `authors[0]`, which depends on insert order — there is no canonical "primary author" concept.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Add Feature tests** for `getAuthorBySlug`, the find-or-stub endpoint, the orphan-prune path on book delete, and each of the three slug normalizers. Necessary before any of the consolidation work below.
2. **Extract a single slug helper** (`App\Support\Slugger::forAuthor($first, $last)`) and use it from `AuthorController::getOrSetToBeCreatedAuthorsByName`, `BookController::handleAuthors`, and `NewBookController::handleAuthors`. Pick one rule (recommend: strip non-alphanumerics, no length cap, or a much higher cap like 100) and migrate existing data so collapse-on-firstOrCreate can be trusted. Companion to the book-side slug helper in `/feature-plans/books.md` item 6.
3. **Make `authors.slug` non-nullable and add a unique index.** Backfill nulls before adding the constraint; surface duplicates as part of the migration so they can be merged manually (or via the merge tool below) instead of failing the migration.
4. **Build an author merge tool** — `POST /authors/{keep_id}/merge/{remove_id}` that re-points `book_author` rows from `remove_id` to `keep_id`, deletes the loser, and returns the merged record. Admin-only; needed once duplicates exist (and they probably already do).
5. **Introduce `FormRequest` classes** for the find-or-stub endpoint and any future author-edit endpoint. Same pattern as the books flow.
6. **Decide the fate of `/create-authors`.** Either:
   - Wire the SPA's new-book flow to call it as the "confirm matches" step (the point of the find-or-stub pattern), or
   - Delete it and the duplicate slug-normalizer it carries.
   The current limbo is the worst option.
7. **Add `bio` (and probably `photo_url`, `birth_year`, `death_year`) to the `authors` table.** `AuthorService` is already returning `bio`; make it real. Then build a minimal author edit form (also unblocks item 9).
8. **Build an author edit endpoint and view.** `PATCH /authors/{id}` with a real `update` method on `AuthorController`, an `AuthorPolicy`, and a small edit form on the detail page. Removes the "edit every book to fix a typo" workaround.
9. **Soft-delete authors** (and remove the silent hard-cascade in `BookController::destroy`'s orphan-prune). Same trait + `deleted_at` strategy as `/feature-plans/books.md` item 10. The orphan prune should mark, not delete.
10. **User-scope `books.readInstances` in `AuthorService::getAuthorWithRelations`.** Mirror the `auth()->id()` filter that `BookController::index` and `BookService::getBookWithRelations` apply. Required before any multi-tenant work.
11. **Build an author index / browse view** — `GET /authors` paginated, alphabetic, filterable by first letter of last name. Wire `AuthorsStore.allAuthors` and `sortedBy` (currently unused) to back it. Unblocks discovery without going through a book.
12. **Surface author-level stats on the detail page.** Total books in catalog, total reads, average rating, first/most-recent read year. Reuses the same `ReadInstance` aggregation logic that `StatisticsService` will end up with — coordinate with `/feature-plans/documentation-backfill.md` tier 3 (statistics).
13. **Link all authors on book rows, not just `authors[0]`.** Either render the full list comma-separated (matching `BookCard`) or add a hover/expand affordance.
14. **Introduce a "primary author" concept.** A flag on `book_author` (`is_primary`) or a dedicated column on `books` (`primary_author_id`). Removes the dependence on insert-order for the index sort and makes the "primary author" link in book rows meaningful.
15. **Stabilize the `AuthorView` error message.** Currently says "Unable to load books at this time" on any failure (copy-pasted from a book view); should reference the author.
16. **Delete `getOneAuthor` from `api/AuthorController.js`** and the unreachable `index` / `create` / `store` / `edit` / `update` / `destroy` stubs from `AuthorController.php`. Either implement them with policies or remove them — currently they're noise that suggests CRUD exists when it doesn't.
17. **De-duplicate `AuthorController::show` vs `getAuthorBySlug`.** Pick one. If `Route::resource('authors', …)` is added later (item 11 likely needs it), `show` should be the canonical handler and `getAuthorBySlug` should be removed (or vice versa); don't keep both.
