---
path: /feature-plans/
status: living
---

# Authors

Tracks rough edges and follow-up work for the Authors taxonomy. Descriptive content lives in `/documentation/authors.md`.

Most of the gnarly behavior here is owned by the book pipeline (attach on create/update, prune on delete). That work is tracked in `/feature-plans/books.md`; items below are author-specific or cross-cut both files.

## Known limitations

### Authorization & ownership

- **Authors are global, by design** (see `/documentation/books.md`). The live risk is not visibility but the orphan-cascade in `BookController::destroy`, which lets either account silently delete an author row the other still cares about — see Future improvements.
- **No `AuthorPolicy`.** The five `Route::resource`-style stub methods on `AuthorController` (`index`, `create`, `store`, `edit`, `update`, `destroy`) are unreachable, so this hasn't mattered yet. The moment any of them gets implemented, a policy needs to land with it.

### Validation & request shape

- **No `FormRequest` classes.** `getOrSetToBeCreatedAuthorsByName` reaches into `$request['authorsData']` and each entry's `name` / `first_name` / `last_name` directly. Missing keys throw undefined-index 500s. Same pattern as the books flow — same fix applies.
- **`/create-authors` does no validation** on name length, character set, or duplicate entries within a single request. Submitting `[{name: ''}, {name: ''}]` happily produces two stubs with empty slugs.

### Data integrity

- **`authors.slug` is unique and `NOT NULL` (since `2026_04_30_000000_make_authors_slug_unique_and_required.php`).** The migration backfilled legacy nulls and deduped colliding rows with numeric suffixes. `firstOrCreate` is no longer the only dedupe — the unique index is the actual guarantee. Races and divergent normalizers now surface as `QueryException` instead of silent duplicates; batch importers should catch the constraint violation and re-fetch.
- **Input-shape divergence is down to one door.** The three book doors and the CSV importer all resolve authors through `AuthorService`; `AuthorController::getOrSetToBeCreatedAuthorsByName` still slugifies the frontend-provided `name` rather than the two name parts. Identical whenever `name` equals first + last, but middle names or suffixes break parity. It has no live caller — see item 4 below.
- **`AuthorService::rename` does not re-slug.** Fixing a typo in a name through the book edit form updates `first_name` / `last_name` and leaves `authors.slug` pointing at the old spelling, so the row no longer matches what a fresh find-or-create would derive and a later ingest of the corrected name creates a *second* author. Deliberate for now: re-deriving the slug can collide with the unique index and 500 a book edit, and resolving that collision (suffix? merge?) is the author-edit surface in item 6 plus the merge tool in item 2.
- **A single-name author is valid everywhere now.** `App\Http\Requests\Concerns\ValidatesAuthorNames` requires a first name *or* a last name across all three book requests, matching what the CSV importer always did. What is still asymmetric is where a lone name goes: the importer accepts `|Aristotle` (last-name-only) and the forms accept either half, so two entries for the same person can differ in which column holds the name while slugging identically — which means they dedupe to one row whose column split depends on who got there first.
- **No author merge tool.** Once duplicates exist (from divergent slugs, typos, "Jr." vs "Jr", etc.), there's no API or UI to combine them — the only fix is manual SQL.
- **Orphan-pruning is silent and irreversible.** When `BookController::destroy` deletes the last book by an author, the author row is hard-deleted with no audit trail. If the book deletion was a misclick, the author has to be re-typed by hand and gets a fresh `author_id`, breaking any external reference. Linked from `/feature-plans/books.md` ("Soft-delete books, versions, and read instances").
- **`bio` is returned by the API but has no column.** `AuthorService::getAuthorWithRelations` includes `bio` in the response payload; the migration doesn't define it and the model doesn't declare it. Reads as `null` today; if a frontend ever depends on it before the column exists, it'll break silently.

### Performance & query shape

- **`AuthorService::getAuthorWithRelations` is one big eager-load with no pagination.** A prolific author with hundreds of books pulls every book, every version, every read instance, every genre, every author of every related book in a single query tree. Fine today; will not be fine at scale.
- ~~**`books.readInstances` is not user-scoped on the author page.**~~ Fixed. `App\Models\Scopes\BelongsToCurrentUser` on `ReadInstance` scopes the eager load, so `AuthorService::getAuthorWithRelations` no longer needs (or carries) an explicit predicate. Pinned by `tests/Feature/UserScoping/TaxonomyScopingTest`.

### API surface

- **`/create-authors` is unused.** Defined and reachable, but the live new-book flow handles authors inline through `POST /create-book`. Either delete the endpoint or rewire the frontend to use it (the latter is the point of the find-or-stub pattern — let the UI confirm matches before committing). Currently the worst of both: the contract exists, isn't enforced, and can drift from the inline path.
- **No `GET /authors` index endpoint.** The five `Route::resource` stubs would naturally cover this, but they're empty. There's no way to list authors short of fetching every book.
- **`AuthorController::show` and `getAuthorBySlug` are duplicates.** Both delegate to `AuthorService::getAuthorWithRelations($slug, 'slug')`. `show` isn't routed (no `Route::resource('authors', …)`) so it's dead, but the duplication is a footgun if someone later wires the resource route — they'll get two ways to do the same thing.

### Extensibility

- **Thin test coverage.** `tests/Feature/Authors/AuthorIngestTest` covers the four ingest doors, the shared name rules, and the `author_ordinal` semantics. Find-or-stub, the author detail payload, and the orphan-prune path on book delete are still uncovered.
- **`AuthorsStore` is mostly stub.** `allAuthors` and `sortedBy` exist but are never read or written; only `currentAuthor` is wired. Not a bug, but anyone extending the store will assume infrastructure exists that doesn't.

### Frontend & UX

- **No author index / browse view.** Users can land on an author page only by clicking through from a book card or table row. There's no `/authors` listing, no alphabetic browse, no search.
- **`getOneAuthor` in `api/AuthorController.js` is dead code.** Builds `/api/authors/{id}`, which 404s. Delete it.
- **Only the primary author is linked from book rows.** `BookTableRow` and `ListItemsTable` both hardcode `book.authors[0]`. Multi-author books surface only one name on the table; the others are reachable only from the book detail page.
- **Author detail page is bookshelf-only.** Reuses `BookshelfTable` and shows nothing about the author themselves — no bio (no column), no photo, no aggregated stats (total books read, average rating across their catalog, first/most-recent read). The page header is just `"{first} {last}"`.
- **No edit affordance for authors.** Fixing a typo in `first_name` requires opening every book by that author and editing through the book edit flow. The "edit author" UI doesn't exist.
- **Author sort on book lists is by the primary author only.** A book by "Smith & Adams" sorts under `authors[0]`, which is now the lowest `author_ordinal` rather than insert order (every ingest door numbers co-authors in input order via `AuthorService::attachToBook`). There is still no way to *change* that order after the fact, and no `is_primary` concept — see item 12.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Add Feature tests** for `getAuthorBySlug`, the find-or-stub endpoint, and the orphan-prune path on book delete. The ingest doors and the shared name rules are covered by `tests/Feature/Authors/AuthorIngestTest`; these three surfaces are not.
2. **Build an author merge tool** — `POST /authors/{keep_id}/merge/{remove_id}` that re-points `book_author` rows from `remove_id` to `keep_id`, deletes the loser, and returns the merged record. Admin-only; needed once duplicates exist (and they probably already do).
3. **Introduce `FormRequest` classes** for the find-or-stub endpoint and any future author-edit endpoint. Same pattern as the books flow.
4. **Decide the fate of `/create-authors`.** Either:
   - Wire the SPA's new-book flow to call it as the "confirm matches" step (the point of the find-or-stub pattern), or
   - Delete it and the duplicate slug-normalizer it carries.
   The current limbo is the worst option.
5. **Add `bio` (and probably `photo_url`, `birth_year`, `death_year`) to the `authors` table.** `AuthorService` is already returning `bio`; make it real. Then build a minimal author edit form (also unblocks item 7).
6. **Build an author edit endpoint and view.** `PATCH /authors/{id}` with a real `update` method on `AuthorController`, an `AuthorPolicy`, and a small edit form on the detail page. Removes the "edit every book to fix a typo" workaround.
7. **Soft-delete authors** (and remove the silent hard-cascade in `BookController::destroy`'s orphan-prune). Same trait + `deleted_at` strategy as `/feature-plans/books.md` ("Soft-delete books, versions, and read instances"). The orphan prune should mark, not delete.
8. ~~**User-scope `books.readInstances` in `AuthorService::getAuthorWithRelations`.**~~ Shipped — see the Known limitations entry above. Solved model-side rather than call-site-side, so a future author surface cannot reintroduce it.
9. **Build an author index / browse view** — `GET /authors` paginated, alphabetic, filterable by first letter of last name. Wire `AuthorsStore.allAuthors` and `sortedBy` (currently unused) to back it. Unblocks discovery without going through a book.
10. **Surface author-level stats on the detail page.** Total books in catalog, total reads, average rating, first/most-recent read year. These are `ScopeResolver` cases plus a surface config — see `/feature-plans/statistics-widgets.md` item 1.
11. **Link all authors on book rows, not just `authors[0]`.** Either render the full list comma-separated (matching `BookCard`) or add a hover/expand affordance.
12. **Introduce a "primary author" concept.** A flag on `book_author` (`is_primary`) or a dedicated column on `books` (`primary_author_id`). Removes the dependence on insert-order for the index sort and makes the "primary author" link in book rows meaningful.
13. **Stabilize the `AuthorView` error message.** Currently says "Unable to load books at this time" on any failure (copy-pasted from a book view); should reference the author.
14. **Delete `getOneAuthor` from `api/AuthorController.js`** and the unreachable `index` / `create` / `store` / `edit` / `update` / `destroy` stubs from `AuthorController.php`. Either implement them with policies or remove them — currently they're noise that suggests CRUD exists when it doesn't.
15. **De-duplicate `AuthorController::show` vs `getAuthorBySlug`.** Pick one. If `Route::resource('authors', …)` is added later (item 9 likely needs it), `show` should be the canonical handler and `getAuthorBySlug` should be removed (or vice versa); don't keep both.
