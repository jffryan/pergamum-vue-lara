---
path: /documentation/
status: living
---

# Authors

## Scope

Covers the `Author` model, the slug-routed author detail page, the admin rename / merge surface (`/admin/authors`), and the find-or-stub helper exposed for the book-creation flow. Author *attachment* during book create/update and the orphan-pruning that runs on book delete are owned by the book pipeline and are documented in `books.md` — this doc links to them rather than restating.

## Summary

An `Author` is a flat taxonomy record (first name, last name, slug) attached to books via the `book_author` pivot. There is **one row per author, shared by every book that credits them**, so a name is stored once and every book reads it from there. Authors are created as a side effect of book creation/update, renamed and merged from `/admin/authors` (or renamed from the book edit form, through the same rule), surfaced on a per-author detail page, and pruned automatically when their last book is deleted.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `GET /author/{slug}` → `AuthorController::getAuthorBySlug` — slug-based detail lookup, the SPA's only entrypoint to an author.
  - `POST /create-authors` → `AuthorController::getOrSetToBeCreatedAuthorsByName` — given a list of `{ name, first_name, last_name }`, returns either the existing `Author` row (matched by slug) or a stub object with `author_id: null`. Designed for the book-creation flow's "find existing vs. stub new" branching; see `new-book-creation.md`. Currently no frontend caller — the live new-book flow handles authors inline through `NewBookController::completeBookCreation`.
  - `GET /authors` → `AuthorController::index` — every author with `books_count`, in filing order (`Author::sortNameExpression()`, then first name). Unpaginated; backs the admin screen.
  - `PUT|PATCH /authors/{author}` → `AuthorController::update` — rename, body `{ first_name, last_name }`. `{author}` binds by `author_id`. Returns the author with its (possibly new) `slug` and `books_count`; a name another author holds is a **409** `author_name_taken` carrying that author as `conflict`.
  - `POST /authors/{author}/merge` → `AuthorController::merge` — `{author}` is the winner, body `{ source_ids: [...] }` the losers. Returns the winner with its new `books_count`.
  - There is no create or delete endpoint: authors are created by the book doors and deleted by the orphan-prune on book delete, or by being merged away.
- **Controllers**: `AuthorController` is thin — `getAuthorBySlug`, `update` and `merge` delegate to the service; `getOrSetToBeCreatedAuthorsByName` holds its own slug-and-lookup logic inline. `AuthorController::conflictResponse()` is the one place a rename conflict becomes a 409, and `BookController::update` uses it too.
- **Name rules live in one place.** `AuthorService` owns them for persistence — `normalize()` (trim + collapse internal whitespace, no lowercasing), `slugFor($first, $last)`, `resolve()` (find-or-create, matching on slug alone because that is what the unique index enforces), `attachToBook()` (resolve each entry, skip ones already attached, number the rest sequentially from the book's current max `author_ordinal`), `rename()` and `merge()` (both below, under *Renaming and merging*). `App\Http\Requests\Concerns\ValidatesAuthorNames` owns the shape rule for requests: **an author needs a first name or a last name, not both.** All three ingest doors (`PUT /books/{id}`, `POST /create-book`, `POST /bulk-upload`) route through both. `tests/Feature/Authors/AuthorIngestTest` pins them together; a new door gets a block there.
- **A single name goes in `first_name`.** Mononyms and organizations — Plato, Aristotle, National Geographic — are stored the way they read, with `last_name` empty. Ordering compensates via `Author::sortNameExpression()` (below) rather than the data compensating for ordering.
- **Services**: `app/Services/AuthorService.php`. The canonical entrypoint is `getAuthorWithRelations($identifier, 'id'|'slug')`, which eager-loads `books.authors`, `books.genres`, `books.readInstances`, `books.versions`, and `books.versions.format` and shapes the response as `{ author: { author_id, first_name, last_name, slug, bio }, books: [{ book, authors, genres, versions, read_instances }] }`. Any new code surfacing an author payload should call this rather than re-implementing the eager-load shape.
- **Models**: `Author` (`author_id` PK, fillable: `first_name`, `last_name`, `slug`). `Author::sortNameExpression()` returns the raw SQL an author files under for ordering — the last name, or the first name when there is no last name (mononyms and organizations); it is used by `BookListing::query()`, which backs both the library listing and the genre detail page, and any new author-ordered listing should use it rather than ordering on `last_name`. `books()` is `belongsToMany(Book, 'book_author', 'author_id', 'book_id')->withPivot('author_ordinal')->withTimestamps()` — the pivot name and FK columns are explicit because the default conventions don't match, and `author_ordinal` is declared on both sides of the relation because code reads it (`CatalogExportService::authorField` sorts on it, and it is what makes `authors[0]` the primary author rather than an accident of insert order).
- **Policies / authorization**: `AuthorPolicy` (`viewAny`, `update`, `merge`), every ability `true` — the same seam as `GenrePolicy`, for a future admin gate. `AuthorController` calls `Gate::authorize` explicitly on `index`, `update` and `merge`. Author records are global; nothing is user-scoped.
- **Migrations**: `2023_09_09_000002_create_authors_table.php` creates the table (`last_name` required, `first_name` nullable). `2026_04_30_000000_make_authors_slug_unique_and_required.php` backfills legacy slugs (deduping with numeric suffixes) and adds `NOT NULL` + a unique index on `slug` — the DB now enforces uniqueness, so any `firstOrCreate` race or slug-helper drift fails loudly instead of silently producing duplicates. `2023_09_09_000003_create_book_author_table.php` is the M2M pivot.

### Frontend

- **API layer**: `resources/js/api/AuthorController.js` exports `getAuthorBySlug(slug)`, `getAllAuthors()`, `updateAuthor(author_id, { first_name, last_name })` and `mergeAuthors(keep_id, source_ids)`.
- **Stores**: `AuthorsStore` (`stores/AuthorsStore.js`) holds `currentAuthor` (the detail page) and `allAuthors` (the admin list, via `fetchAllAuthors({ force })`). `renameAuthor` and `mergeAuthors` refetch `allAuthors` after the write — a merge changes counts on rows it didn't name — and push the result into `BooksStore.replaceAuthor()`, which rewrites the author on every book already cached this session (re-pointing merged-away ids and de-duplicating). Neither catches: the 409's `conflict` payload is the caller's.
- **Utils**: `utils/authorList.js` — `authorName(author)` ("First Last", or the one half a mononym has) and `filterAuthors(authors, term)` (case- and accent-insensitive, matches the slug too).
- **Service**: none dedicated. Author rows for the new-book page are trimmed and blank-filtered in `utils/newBookForm.js` (`namedAuthors`); the server resolves them through `AuthorService::attachToBook`.
- **Routes**: `router/author-routes.js` — single route `/authors/:slug` (named `authors.show`). Routing is slug-only; there is no ID-based route. The admin screen is `/admin/authors` (`admin.authors`) in `admin-routes.js`.
- **Views**: `views/AuthorView.vue` — fetches via `getAuthorBySlug`, stores result in `AuthorsStore.currentAuthor`, and renders the author's books through the shared `BookshelfTable`.
- **Components**: `components/admin/authors/` — `AuthorsIndex` (search, the table capped at 100 rows with a "search to narrow" note, merge selection), `AuthorRow` (name linked to the author page with its `/authors/<slug>` underneath, inline two-field rename, and the rename → 409 → "Merge into them" handoff), `MergeAuthorsBar` (N→1 merge through `ConfirmAction`, winner defaulting to the most-credited). Mounted at `/admin/authors` via `AdminActionView`; see `admin.md`. The detail page reuses `BookshelfTable`, and book-creation author input is covered in `new-book-creation.md`.

## Non-obvious decisions and gotchas

- **`author_id` custom PK and explicit pivot wiring.** `Author::$primaryKey = 'author_id'`, and `books()` passes the pivot name (`book_author`) and FK columns (`author_id`, `book_id`) explicitly. New relations against `Author` must do the same; relying on Eloquent defaults will silently match on `id`.
- **Author slug generation is centralized on `AuthorService::slugFor()`,** which normalizes both halves and hands the joined name to `App\Support\Slugger::for()`. The three book doors and the CSV importer all call it. `AuthorController::getOrSetToBeCreatedAuthorsByName` is the one holdout — it slugifies the frontend-provided `name` field instead of the two parts, which is identical whenever `name` equals first + last but can diverge on middle names or suffixes. That endpoint has no live caller and its fate is an open item in `/feature-plans/authors.md`. Every call site inherits the same 60-char cap, hyphen-boundary truncation, and `Str::slug()` sanitization. Existing rows created under the older hand-rolled normalizers (which kept apostrophes, varied on length caps, and varied on non-alphanumeric stripping) may still mismatch the current rule — a find-or-create can fail to dedupe against legacy data until a backfill runs.
- **`authors.slug` is unique at the DB level** (since `2026_04_30_000000_make_authors_slug_unique_and_required.php`). `firstOrCreate` still does the lookup, but the unique index is the actual guarantee — concurrent inserts that race past `firstOrCreate` will now hit a `QueryException` rather than silently duplicating. Callers that batch-insert (bulk upload, future imports) should either catch the constraint violation and re-fetch or pre-resolve the slug inside a transaction.
- **`getOrSetToBeCreatedAuthorsByName` is a find-or-stub, not a writer.** Despite the route name `/create-authors`, this endpoint does not persist anything for new authors — it returns a stub with `author_id: null` that the caller is expected to round-trip back into `POST /create-book`, which is what actually inserts the row. Don't add side-effecting persistence here without auditing callers.
- **Renaming an author from a book's edit form renames them on every book.** The form round-trips each author's `author_id`, and `BookController::updateAuthors` treats an edited name next to an id as a rename of that one shared row — the same `AuthorService::rename()` the admin screen calls. It is not "credit this book to a different person"; that is removing the author and adding another, which the edit form can't do yet (nothing detaches).
- **`AuthorService::getAuthorWithRelations` does not user-scope `readInstances`.** The eager-load on `books.readInstances` pulls every user's reads for every book on the author's page. Books and versions are global (matching the convention in `books.md`), but read history elsewhere in the app *is* scoped via `auth()->id()` — the author detail surface is the exception. Treat this as load-bearing for the current "related books by same author" view; if you start surfacing per-user read state on this page, add the user-scope filter.
- **`bio` is returned but not stored.** `getAuthorWithRelations` includes `bio` in the response, but the migration has no `bio` column and the model doesn't declare it. The field reads as `null` today; if you wire bio in, add a migration before frontend code starts depending on it.

## Usage notes

### Fetching an author detail page

`GET /author/{slug}` returns:

```
{
  author: { author_id, first_name, last_name, slug, bio },
  books: [
    {
      book: { book_id, title, slug },
      authors: [...],
      genres: [...],
      versions: [...],   // each with format eager-loaded
      read_instances: [...]
    },
    ...
  ]
}
```

The SPA links into this view via `{ name: 'authors.show', params: { slug } }`; `BookTableRow` and `ListItemsTable` already do this for the primary author of each book.

### Find-or-stub during book creation

`POST /create-authors` with `{ authorsData: [{ name, first_name, last_name }, ...] }` returns `{ authors: [...] }` where each entry is either a full `Author` row (existing match by slug) or `{ author_id: null, first_name, last_name, slug }` (stub). Stubs are completed by passing the same shape back into `POST /create-book`, which calls `firstOrCreate` and persists. The current SPA doesn't call this endpoint — it sends the raw author input through to `POST /create-book` directly — but it remains the contract for any caller that wants to confirm matches before committing.

### Renaming and merging

**The name is the source of truth, and the slug follows it.** Books point at authors by `author_id`, so a rename needs no propagating to books — every book, list and listing reads the one row. What *does* have to move is `authors.slug`: it is both the author page's URL and the key every ingest door finds authors by (`AuthorService::resolve()`). A rename that left it on the old spelling would keep the page on the old URL and, worse, make the next import or new-book entry of the corrected name miss this row and create a second author. So `AuthorService::rename()`:

1. Normalizes both halves (the same `normalize()` as ingest) and derives the new slug with `slugFor()`.
2. If that slug is free, the author moves to it. The old URL stops resolving — there is no redirect.
3. If another author holds it, the two names are the same person as far as the catalog can tell, so this is a merge, not a rename: it throws `AuthorNameConflictException`, rendered as a 409 carrying the other author. Nothing is written — from the book edit form that includes the rest of the book edit, which rolls back.
4. …except when the edit is cosmetic — the old and new names slug alike (casing, spacing). Then the author keeps whatever slug it has, so a row the 2026-04-30 migration de-duplicated to `john-smith-2` can fix its casing without being told it collides with `john-smith`.

`AuthorService::merge($keep, $sourceIds)` folds losers into the winner in one transaction. It **re-points** each loser's `book_author` row rather than detaching and re-attaching, so the winner takes the loser's `author_ordinal` on that book — a duplicate that was a book's primary author leaves the winner primary. A book already crediting both keeps one row, at the lower (better) ordinal. The losers are then deleted. Irreversible, with no audit trail.

The admin screen strings these together: a rename that 409s offers "Merge into them", which merges the row being edited into the conflicting author. `tests/Feature/Authors/AuthorsAdminTest` pins all of the above, including the book-form path.

### Creating and deleting authors

There is no direct API. Authors are created/attached by `POST /create-book` and `POST /bulk-upload`, and pruned to zero books by `DELETE /books/{id}` (which returns `deleted_authors` on the response) or deleted by a merge. See `books.md` for the book-side logic.

## Related

- Plan file: `/feature-plans/authors.md` — future improvements and known limitations.
- `/documentation/books.md` — author attachment, update, and orphan-prune are owned by the book pipeline.
- `/documentation/new-book-creation.md` — the multi-step creation flow that consumes `/create-authors` (or bypasses it).
- `/documentation/genres.md`, `/documentation/formats.md` — sibling taxonomy docs.
