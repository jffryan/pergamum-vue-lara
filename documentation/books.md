---
path: /documentation/
status: living
---

# Books

## Scope

Covers the `Book` / `Version` / `ReadInstance` domain — the catalog itself and the per-user reading events tied to it. Aggregations across read history (year-browse, completed views) are noted here briefly but lives in `read-history.md`. Author and genre taxonomy that books reference is in `authors.md` and `genres.md`.

## Summary

The Book domain is the core of Pergamum: every other feature (lists, authors, genres, statistics, bulk upload) hangs off it. A `Book` is a logical work, a `Version` is a specific edition/format of that work (paperback, audiobook, etc.), and a `ReadInstance` is a single per-user reading event tied to a book and version with a date and rating.

```
Book ──< Version ──< ReadInstance
  │                      ▲
  └──< ReadInstance ─────┘   (also linked directly to Book)
  │
  ├──>< Author    (M2M via book_author)
  └──>< Genre     (M2M via book_genre)
```

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `Route::resource('/books', BookController::class)` — index/store/show/update/destroy.
  - `GET /book/{slug}` — slug-based detail lookup (the SPA's primary entrypoint to a book).
  - `GET /completed/years`, `GET /completed/{year}` — read-history aggregations.
  - `POST /add-read-instance` — record a new read against an existing book+version.
  - `POST /versions` (`VersionController::addNewVersion`) — add a version to an existing book.
  - Book *creation* uses a two-step flow on `NewBookController`: `POST /create-book/title` (find-or-stub by title) then `POST /create-book` (complete with authors/versions/genres/read history).

- **Controllers**: `BookController`, `VersionController`, `NewBookController`. Controllers are intentionally thin and delegate to:
- **Services**: `app/Services/BookService.php` is the canonical entrypoint — `getBookWithRelations($idOrSlug, 'id'|'slug')` is what any new code surfacing a book payload should call. Also exposes `getBooksList($books)`, `getAvailableYears()`, `getCompletedItemsForYear($year)`. Use these from any new controller that needs a book payload rather than re-implementing eager-load shapes.
- **Models**: `Book` (`book_id`), `Version` (`version_id`), `ReadInstance` (`read_instances_id`). All fillables are explicit; see the model files for the canonical list.
- **Policies / authorization**: none. Books, versions, and read instances have no policy; controllers do not call `authorize()`. Read instances are scoped by `auth()->id()` in queries; books and versions are global.
- **Migrations**: `books`, `versions`, `read_instances`, plus pivots `book_author` and `book_genre`.

### Frontend

- **API layer**: `resources/js/api/BookController.js` and `VersionController.js`, both built on `apiHelpers.js` (`makeRequest` / `buildUrl`). Components must not call axios directly.
- **Stores**: `BooksStore` (catalog/list state), `NewBookStore` (multi-step creation form). Read history and version edits flow through `BooksStore`.
- **Service**: `resources/js/services/BookServices.js` orchestrates creation/edit flows (validation, error surfacing) across stores.
- **Routes**: `router/book-routes.js`. Detail pages route by slug (`/book/{slug}`); the numeric-ID endpoint exists but the SPA does not use it.
- **Views**: book list, book detail (slug-routed), book create, book edit, plus completed-by-year views.

## Non-obvious decisions and gotchas

- **Custom primary keys throughout.** `book_id`, `version_id`, `read_instances_id` (note the plural `instances`), `author_id`, `genre_id`, `format_id`. Eloquent relations declare the FK explicitly (`hasMany(Version::class, 'book_id')`); always pass the FK name when adding new relations or you'll silently get `id`-based queries that return nothing.
- **`ReadInstance` is dual-attached.** It carries both `book_id` and `version_id`. `addReadInstance` saves through *both* `$book->readInstances()` and `$version->readInstances()`, and the `book` listing query joins on `read_instances.book_id`. When introducing new read-history code, set both FKs — querying only one side will look correct in isolation but break the other. The version-belongs-to-book invariant is enforced model-side: `ReadInstance::booted()` registers a `saving` listener that throws `\DomainException` if `version_id` is set and its `Version.book_id` doesn't match the row's `book_id`. `addReadInstance` short-circuits the same check earlier and returns `422 { reason_code: 'version_book_mismatch' }`.
- **`ReadInstance::setRatingAttribute` doubles the input.** A 4.5-star UI rating is stored as `9`. Anything writing to `rating` via mass assignment goes through this mutator; anything reading it gets the doubled value back and must halve for display. Bulk inserts that bypass Eloquent (e.g. `DB::table()->insert`) will skip the mutator — don't.
- **Read instances are user-scoped, books and versions are not.** `ReadInstance` has `user_id`; `Book` and `Version` are global. The book index eager-loads `readInstances` filtered by `auth()->id()`; replicate that constraint anywhere you surface read history, or one user will see another's reads.
- **`date_completed` on `Book` is formatted on read.** The accessor returns `m/d/Y`, so the raw DB value is not what callers see. Don't compare it as a date string in PHP without re-parsing.
- **`ReadInstance::serializeDate` returns `Y-m-d`.** JSON responses use ISO date-only for `date_read`; the frontend parses with `Carbon.createFromFormat('Y-m-d', ...)` on the way back in.
- **Slugs are generated from title at create/update time** via `App\Support\Slugger::for($title)` — a single helper used by `BookController::createOrGetBook`, `BookController::updateBook`, `NewBookController::createOrGetBookByTitle`, and `NewBookController::createBook`. Output is sanitized via `Str::slug()` (lowercase, transliterated, non-alphanumerics → hyphens) and capped at 60 characters; if truncation is needed it cuts at the nearest hyphen boundary ≤ 60 (no `...` suffix). `books.slug` is uniquely indexed at the DB level. `NewBookController::createBook` calls `generateUniqueSlug` to append `-2`/`-3` on collision; `BookController::createOrGetBook` instead treats a slug match as "same book." Author slug generation in `updateAuthors` and `handleAuthors` also routes through `Slugger::for(trim("$first_name $last_name"))`, matching the convention.
- **`store` short-circuits on existing books.** `BookController::store` checks `wasRecentlyCreated`; if the slug already exists, it only appends new versions and skips the authors/genres/read-instance branches. The "add a version to an existing book" path is intentionally the same endpoint as create — this is not visible from the route definition.
- **`update` does not add new read instances.** It only updates ones that already have a `read_instances_id` (filtered in the controller). New read entries from an edit form are dropped silently — the dedicated path is `POST /add-read-instance`.
- **`update` expects a doubly-nested payload** (`request.formData.book`, `request.formData.authors`, …). Artifact of how the frontend sends edit forms; mirror this shape in any new caller.
- **`destroy` cascades and prunes orphaned authors.** Deleting a book deletes its versions and read instances, then deletes any author who is left with zero books. The response includes `deleted_authors` so the UI can confirm.
- **`Book::formats()` is a `belongsToMany` *through* the `versions` table.** It's a convenience for "what formats does this book exist in"; it is not a true M2M and there is no `book_format` pivot.
- **`prepareVersions` branches on format name.** It looks at `$format->name == 'Audiobook'` / `'Paper'` to decide which fields apply. Adding a new format with different field semantics requires editing this method directly.

## Usage notes

### Creating a book (typical flow)

1. `POST /create-book/title` with `{ title }` → returns either an existing book (so the UI can branch to "add a version") or a stub indicating a new book should be filled in.
2. `POST /create-authors` with the author list → returns existing/created author records the UI can confirm.
3. `POST /create-book` with the full payload (`book`, `authors`, `versions`, `genres`, optional `readInstances`).

### Adding a version to an existing book

Either `POST /books` with the existing book's title (the slug match triggers the version-only branch) or `POST /versions` with `{ book_id, page_count, format_id, audio_runtime?, nickname? }`. Prefer the latter for clarity in new code.

### Recording a read

`POST /add-read-instance` with `{ readInstance: { book_id, version_id, date_read: 'Y-m-d', rating } }`. `user_id` is taken from the session; do not send it. `rating` is the UI value (e.g. 4.5) — the mutator doubles it on write.

### Fetching a book

Always go through `BookService::getBookWithRelations`. The two entrypoints:
- `GET /books/{book_id}` — numeric ID.
- `GET /book/{slug}` — slug. This is what the SPA uses for detail pages.

Both return the same shape; pick based on what the caller has.

### Listing / searching

`GET /books` paginates (default 20, `?limit=` to override), sorts by primary author's last name, and accepts `?search=` (matches title or author name) and `?format=` (filters by format name). Response shape: `{ books, pagination: { total, perPage, currentPage, lastPage, from, to } }`. Items are run through `BookService::getBooksList`, which is the canonical "card-shaped" book payload — reuse it instead of building a parallel projection.

## Related

- Plan file: `/feature-plans/books.md` — future improvements and known limitations for this domain.
- `/documentation/lists.md` — lists hold versions (not books); the rating-doubling and user-scoping conventions documented here apply there too.
- `/documentation/authors.md`, `/documentation/genres.md`, `/documentation/formats.md` — taxonomy that books reference.
- `/documentation/read-history.md` — year-browse and completed-view aggregations built on `ReadInstance`.
- `/documentation/new-book-creation.md` — the multi-step creation flow summarized above.