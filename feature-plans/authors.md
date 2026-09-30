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

### Validation & request shape

- **No `FormRequest` classes.** `getOrSetToBeCreatedAuthorsByName` reaches into `$request['authorsData']` and each entry's `name` / `first_name` / `last_name` directly. Missing keys throw undefined-index 500s. Same pattern as the books flow — same fix applies.
- **`/create-authors` does no validation** on name length, character set, or duplicate entries within a single request. Submitting `[{name: ''}, {name: ''}]` happily produces two stubs with empty slugs.

### Data integrity

- **`authors.slug` is unique and `NOT NULL` (since `2026_04_30_000000_make_authors_slug_unique_and_required.php`).** The migration backfilled legacy nulls and deduped colliding rows with numeric suffixes. `firstOrCreate` is no longer the only dedupe — the unique index is the actual guarantee. Races and divergent normalizers now surface as `QueryException` instead of silent duplicates; batch importers should catch the constraint violation and re-fetch.
- **Input-shape divergence is down to one door.** The three book doors and the CSV importer all resolve authors through `AuthorService`; `AuthorController::getOrSetToBeCreatedAuthorsByName` still slugifies the frontend-provided `name` rather than the two name parts. Identical whenever `name` equals first + last, but middle names or suffixes break parity. It has no live caller — see Future improvements, "Decide the fate of `/create-authors`".
- **A renamed author's old URL 404s.** `AuthorService::rename` moves the slug with the name and keeps no record of the old one, so a bookmarked or externally linked `/authors/<old-slug>` stops resolving. A `author_slug_redirects` table (or a `previous_slugs` column) consulted by `getAuthorBySlug` would fix it; books have the same gap.
- **Merges are irreversible and unrecorded.** `AuthorService::merge` deletes the losers outright. Same gap as genre merge — see `/feature-plans/admin.md` ("Audit log table").
- **A single-name author is valid everywhere now.** `App\Http\Requests\Concerns\ValidatesAuthorNames` requires a first name *or* a last name across all three book requests, matching what the CSV importer always did. What is still asymmetric is where a lone name goes: the importer accepts `|Aristotle` (last-name-only) and the forms accept either half, so two entries for the same person can differ in which column holds the name while slugging identically — which means they dedupe to one row whose column split depends on who got there first.
- **Orphan-pruning is silent and irreversible.** When `BookController::destroy` deletes the last book by an author, the author row is hard-deleted with no audit trail. If the book deletion was a misclick, the author has to be re-typed by hand and gets a fresh `author_id`, breaking any external reference. Linked from `/feature-plans/books.md` ("Soft-delete books, versions, and read instances").
- **`bio` is returned by the API but has no column.** `AuthorService::getAuthorWithRelations` includes `bio` in the response payload; the migration doesn't define it and the model doesn't declare it. Reads as `null` today; if a frontend ever depends on it before the column exists, it'll break silently.

### Performance & query shape

- **`AuthorService::getAuthorWithRelations` is one big eager-load with no pagination.** A prolific author with hundreds of books pulls every book, every version, every read instance, every genre, every author of every related book in a single query tree. Fine today; will not be fine at scale.

### API surface

- **`/create-authors` is unused.** Defined and reachable, but the live new-book flow handles authors inline through `POST /create-book`. Either delete the endpoint or rewire the frontend to use it (the latter is the point of the find-or-stub pattern — let the UI confirm matches before committing). Currently the worst of both: the contract exists, isn't enforced, and can drift from the inline path.

### Extensibility

- **Thin test coverage.** `AuthorIngestTest` covers the ingest doors and name rules, `AuthorsResourceTest` the detail payload, and `AuthorsAdminTest` rename and merge. Find-or-stub and the orphan-prune path on book delete are still uncovered.

### Frontend & UX

- **No author index / browse view.** Users can land on an author page only by clicking through from a book card or table row. There's no `/authors` listing, no alphabetic browse, no search.
- **Only the primary author is linked from book rows.** `BookTableRow` and `ListItemsTable` both hardcode `book.authors[0]`. Multi-author books surface only one name on the table; the others are reachable only from the book detail page.
- **Author detail page is bookshelf-only.** Reuses `BookshelfTable` and shows nothing about the author themselves — no bio (no column), no photo, no aggregated stats (total books read, average rating across their catalog, first/most-recent read). The page header is just `"{first} {last}"`.
- **Renaming is only in `/admin/authors` and the book edit form.** The author detail page has no "Rename" link into the admin screen. And the book edit form's author fields *rename* the shared author (on every book) — there is no way from that form to re-credit a book to a different person, because nothing detaches an author from a book.
- **Author sort on book lists is by the primary author only.** A book by "Smith & Adams" sorts under `authors[0]`, which is now the lowest `author_ordinal` rather than insert order (every ingest door numbers co-authors in input order via `AuthorService::attachToBook`). There is still no way to *change* that order after the fact, and no `is_primary` concept — see Future improvements, "Introduce a 'primary author' concept".

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Add Feature tests** for the find-or-stub endpoint and the orphan-prune path on book delete.
2. **Introduce a `FormRequest`** for the find-or-stub endpoint. Same pattern as the books flow.
3. **Decide the fate of `/create-authors`.** Either:
   - Wire the SPA's new-book flow to call it as the "confirm matches" step (the point of the find-or-stub pattern), or
   - Delete it and the duplicate slug-normalizer it carries.
   The current limbo is the worst option.
4. **Add `bio` (and probably `photo_url`, `birth_year`, `death_year`) to the `authors` table.** `AuthorService` is already returning `bio`; make it real, and grow the admin rename form (`AuthorRow`) into an edit form for them.
5. **Link the author page to its edit.** A "Rename" link on `AuthorView` into `/admin/authors` pre-filtered to that author.
6. **Let the book edit form detach and re-credit authors.** Today an edited name next to an `author_id` renames that author everywhere; "this book is actually by someone else" needs a remove-author control and a detach path in `BookController::updateAuthors`.
7. **Redirect renamed authors' old slugs** — see Known limitations, "A renamed author's old URL 404s".
8. **Soft-delete authors** (and remove the silent hard-cascade in `BookController::destroy`'s orphan-prune). Same trait + `deleted_at` strategy as `/feature-plans/books.md` ("Soft-delete books, versions, and read instances"). The orphan prune should mark, not delete.
9. **Build an author index / browse view.** `GET /authors` exists (unpaginated, filing order, with `books_count`) and `AuthorsStore.allAuthors` holds it for the admin screen; a user-facing `/authors` alphabetic browse could reuse both, adding pagination if the list outgrows one payload.
10. **Surface author-level stats on the detail page.** Total books in catalog, total reads, average rating, first/most-recent read year. These are `ScopeResolver` cases plus a surface config — see `/feature-plans/statistics-widgets.md` item 1.
11. **Link all authors on book rows, not just `authors[0]`.** Either render the full list comma-separated (matching `BookCard`) or add a hover/expand affordance.
12. **Introduce a "primary author" concept.** A flag on `book_author` (`is_primary`) or a dedicated column on `books` (`primary_author_id`). Removes the dependence on insert-order for the index sort and makes the "primary author" link in book rows meaningful.
13. **Stabilize the `AuthorView` error message.** Currently says "Unable to load books at this time" on any failure (copy-pasted from a book view); should reference the author.
