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
  - `PATCH /versions/{version}/discard`, `PATCH /versions/{version}/restore` (`VersionController::discard` / `restore`) — mark a copy as no longer owned, or undo that.
  - Book *creation* uses a two-step flow on `NewBookController`: `POST /create-book/title` (find-or-stub by title) then `POST /create-book` (complete with authors/versions/genres/read history).

- **Controllers**: `BookController`, `VersionController`, `NewBookController`. Controllers are intentionally thin and delegate to:
- **Services**: `app/Services/BookService.php` is the canonical entrypoint — `getBookWithRelations($idOrSlug, 'id'|'slug')` is what any new code surfacing a book payload should call. Also exposes `getBooksList($books)`, `getAvailableYears()`, `getCompletedItemsForYear($year)`. Use these from any new controller that needs a book payload rather than re-implementing eager-load shapes.
- **Models**: `Book` (`book_id`), `Version` (`version_id`), `ReadInstance` (`read_instance_id`). All fillables are explicit; see the model files for the canonical list.
- **Policies / authorization**: none. Books, versions, and read instances have no policy; controllers do not call `authorize()`. Read instances are scoped by `auth()->id()` in queries; books and versions are global.
- **Migrations**: `books`, `versions`, `read_instances`, plus pivots `book_author` and `book_genre`.

### Frontend

- **API layer**: `resources/js/api/BookController.js` and `VersionController.js`, both built on `apiHelpers.js` (`makeRequest` / `buildUrl`). Components must not call axios directly.
- **Stores**: `BooksStore` (catalog/list state), `NewBookStore` (multi-step creation form). Read history and version edits flow through `BooksStore`.
- **Service**: `resources/js/services/BookServices.js` orchestrates creation/edit flows (validation, error surfacing) across stores.
- **Routes**: `router/book-routes.js`. Detail pages route by slug (`/book/{slug}`); the numeric-ID endpoint exists but the SPA does not use it.
- **Views**: book list, book detail (slug-routed), book create, book edit, plus completed-by-year views. `LibraryView` reads `?discarded=` off the route query and renders an "On the shelf" / "Discarded" toggle; the mode is threaded into the API call, the pagination links, and a watcher (page stays at 1 when toggling, so `currentPage` alone won't refetch).
- **Components**: `VersionTable` / `VersionTableRow` render a book's copies. The row owns the discard affordance — a "Discard" button that reveals an inline, optional date input, and a "Restore" button once discarded. Both emit up through `VersionTable` to `BookView`, which calls the API and merges the response via `BooksStore.replaceVersion(bookId, version)`.

## Non-obvious decisions and gotchas

- **Custom primary keys throughout.** `book_id`, `version_id`, `read_instance_id`, `author_id`, `genre_id`, `format_id` — all singular, even where the table is plural. Eloquent relations declare the FK explicitly (`hasMany(Version::class, 'book_id')`); always pass the FK name when adding new relations or you'll silently get `id`-based queries that return nothing.
- **`ReadInstance` is dual-attached.** It carries both `book_id` and `version_id`, and the `book` listing query joins on `read_instances.book_id`. (`addReadInstance` used to save through *both* `$book->readInstances()` and `$version->readInstances()`; it is one `create()` in a transaction now, with both FKs set from the validated payload.) When introducing new read-history code, set both FKs — querying only one side will look correct in isolation but break the other. The version-belongs-to-book invariant is enforced model-side: `ReadInstance::booted()` registers a `saving` listener that throws `\DomainException` if `version_id` is set and its `Version.book_id` doesn't match the row's `book_id`. `addReadInstance` short-circuits the same check earlier and returns `422 { reason_code: 'version_book_mismatch' }`.
- **`ReadInstance::setRatingAttribute` doubles the input.** A 4.5-star UI rating is stored as `9`. Anything writing to `rating` via mass assignment goes through this mutator; anything reading it gets the doubled value back and must halve for display. Bulk inserts that bypass Eloquent (e.g. `DB::table()->insert`) will skip the mutator — don't.
- **Read instances are user-scoped, books and versions are not — and that is the intended shape.** `ReadInstance` and `BookList` carry `user_id`; `Book`, `Version`, `Author` and `Genre` are global and deliberately have no owner column. Pergamum is a **shared catalog with per-user reading state**: at most one further account is expected and it belongs to the same household, so two people browsing one library is the feature, not a leak (decided 2026-08-03). Don't add ownership columns or per-resource policies to the catalog tables.

  The corollary is that `auth()->id()` scoping on reads and lists is the *only* thing separating the two users, so it has to be right. The book index eager-loads `readInstances` filtered by `auth()->id()`; replicate that constraint anywhere you surface read history. `AuthorService::getAuthorWithRelations` and `GenreController::show` currently don't — see the authors and genres plans.
- **`Book` has no completion date of its own.** `date_completed` used to appear in `$fillable` with a formatting accessor, but no such column has ever existed — it was dead code that read as live. Both are gone. "When did I finish this" comes from `read_instances.date_read`; derive it there rather than reintroducing a stored column.
- **`ReadInstance::serializeDate` returns `Y-m-d`.** JSON responses use ISO date-only for `date_read`; the frontend parses with `Carbon.createFromFormat('Y-m-d', ...)` on the way back in.
- **Slugs are generated from title at create/update time** via `App\Support\Slugger::for($title)` — a single helper used by `BookController::createOrGetBook`, `BookController::updateBook`, `NewBookController::createOrGetBookByTitle`, and `NewBookController::createBook`. Output is sanitized via `Str::slug()` (lowercase, transliterated, non-alphanumerics → hyphens) and capped at 60 characters; if truncation is needed it cuts at the nearest hyphen boundary ≤ 60 (no `...` suffix). `books.slug` is uniquely indexed at the DB level. `NewBookController::createBook` calls `generateUniqueSlug` to append `-2`/`-3` on collision; `BookController::createOrGetBook` instead treats a slug match as "same book." Author slug generation in `updateAuthors` and `handleAuthors` also routes through `Slugger::for(trim("$first_name $last_name"))`, matching the convention.
- **`store` short-circuits on existing books.** `BookController::store` checks `wasRecentlyCreated`; if the slug already exists, it only appends new versions and skips the authors/genres/read-instance branches. The "add a version to an existing book" path is intentionally the same endpoint as create — this is not visible from the route definition.
- **`update` does not add new read instances.** It only updates ones that already carry a `read_instance_id` (filtered in the controller). New read entries from an edit form are dropped silently — the dedicated path is `POST /add-read-instance`. Covered by `tests/Feature/Books/UpdateReadInstancesTest.php`.
- **`update` takes a flat payload** — `book`, `authors`, `genres`, `versions`, `readInstances` as top-level keys. It used to arrive wrapped as `request.formData.…`, an envelope that only ever named a variable in the edit view; `UpdateBookRequest` describes the flat shape now.
- **Validation lives in `app/Http/Requests/`, not the controllers.** Every write endpoint on this surface takes a `FormRequest` extending `ApiFormRequest`: `StoreBookRequest`, `UpdateBookRequest`, `StoreReadInstanceRequest`, `StoreVersionRequest`, `CreateBookTitleRequest`, `CompleteBookCreationRequest`. Two conventions to keep when adding one: the request exposes *accessors* (`title()`, `versions()`, `existingReadInstances()`) rather than handing back a raw `validated()` array, so the controller doesn't re-derive shape; and a rule whose failure has a caller-visible meaning declares a `reason_code` in `reasonCodes()`, keyed by `field.rule` with wildcards allowed (`readInstances.*.rating.rating`). That is what keeps `rating_out_of_range` and `version_book_mismatch` on the wire now that the checks are validation rather than hand-rolled controller branches.
- **A bad field in the body is a 422, including one that names a missing row.** `POST /add-read-instance` with an unknown `book_id` used to `findOrFail` into a 404; it now reports `422` naming `readInstance.book_id`. 404 is reserved for the URL's resource. 
- **`destroy` cascades and prunes orphaned authors.** Deleting a book deletes its versions and read instances, then deletes any author who is left with zero books. The response includes `deleted_authors` so the UI can confirm.
- **`Book::formats()` is a `belongsToMany` *through* the `versions` table.** It's a convenience for "what formats does this book exist in"; it is not a true M2M and there is no `book_format` pivot.
- **Every write path asks the format what it carries.** `Format::lengthFieldsFrom($payload)` reduces a version payload to the length fields the format declares via `expects_page_count` / `expects_audio_runtime`. All four writers go through it: `prepareVersions` (create), `updateVersions` (edit), `StoreVersionRequest` (`POST /versions`) and `CompleteBookCreationRequest` (the multi-step flow). The last two used to pass length fields straight through — that is why the reduction moved onto the model from a private controller method. A field the format expects but the payload omits stores null; a field the format does not expect is nulled regardless of what was sent. Adding a medium with different field semantics is a row in `formats`, not an edit here — see `/documentation/formats.md`.
- **Discarded is a state on `Version`, deliberately *not* a `Format`.** A copy we no longer own is recorded with `versions.is_discarded` + `versions.discarded_at`, leaving `format_id` intact. Format is the medium (Physical, Audiobook, Ebook); collapsing the two would overwrite the medium of every discarded copy — which matters because `ReadInstance` hangs off `Version`, so past reads would lose their format, and because the format decides whether `page_count` / `audio_runtime` apply at all. Do not add a "Discarded" row to `formats`.
- **`is_discarded` is the state; `discarded_at` is optional provenance.** Much of the historical library was got rid of at an unrecoverable point in the past, so `discarded_at = null` means "discarded, date unknown" — it does **not** mean "not discarded". Never infer the state from the date; query through `Version::scopeDiscarded()` / `scopeNotDiscarded()`. Same shape as the nullable `read_instances.date_read`.
- **A book is "discarded" only when *every* version is.** The rule lives in `Book::scopeOnShelf()` / `Book::scopeFullyDiscarded()`; `BookController::applyDiscardedFilter` only maps the `?discarded=` query string onto them, and the `newestBooks` statistics metric calls the scopes directly. Owning the paperback but having got rid of the audiobook leaves the book on the shelf. Books with zero versions count as on-shelf so they never silently disappear. There is no *global* scope — any new listing endpoint has to call `onShelf()` itself.
- **Keep the OR-terms in `searchBooks` wrapped.** The title/author `orWhere` chain sits inside `where(function ($q) {…})`. Ungrouped, any `AND` clause appended afterwards — the shelf filter, for one — binds to the last OR term only. Pinned by `DiscardedBooksFilterTest::test_search_obeys_the_same_shelf_filter`.
- **`BookController::update` does not touch the discard columns.** `updateVersions` fills only `format_id` / `nickname` plus whatever length fields the format declares, so editing a book can't accidentally un-discard a copy. Keep it that way — discarding is a separate, explicit transition.
- **The search branch's `orWhere` chain is grouped.** `searchBooks` wraps its title/first-name/last-name terms in a closure. Without that grouping, any `AND` clause appended afterwards (the shelf filter) binds only to the last `orWhere` term because `AND` has higher precedence — the search would leak discarded books. Preserve the grouping when adding search terms.

## Usage notes

### Creating a book (typical flow)

1. `POST /create-book/title` with `{ title }` → returns either an existing book (so the UI can branch to "add a version") or a stub indicating a new book should be filled in.
2. `POST /create-authors` with the author list → returns existing/created author records the UI can confirm.
3. `POST /create-book` with the full payload (`book`, `authors`, `versions`, `genres`, optional `readInstances`).

### Adding a version to an existing book

Either `POST /books` with the existing book's title (the slug match triggers the version-only branch) or `POST /versions` with `{ book_id, page_count, format_id, audio_runtime?, nickname? }`. Prefer the latter for clarity in new code.

### Discarding a copy

`PATCH /versions/{version_id}/discard` with an optional body `{ discarded_at: 'Y-m-d' | null }`. Omit the key (or send `null`) when the date isn't known — that is the expected case for anything got rid of before tracking started. Sending the key explicitly always writes it, including `null`, which clears a previously-recorded date; omitting the key on a re-discard preserves whatever is already there. Returns the updated version with `format` loaded. `PATCH /versions/{version_id}/restore` clears both the flag and the date. Neither endpoint takes a policy — versions are global, like the rest of the catalog.

### Recording a read

`POST /add-read-instance` with `{ readInstance: { book_id, version_id, date_read: 'Y-m-d', rating } }`. `user_id` is taken from the session; do not send it. `rating` is the UI value (e.g. 4.5) — the mutator doubles it on write.

### Fetching a book

Always go through `BookService::getBookWithRelations`. The two entrypoints:
- `GET /books/{book_id}` — numeric ID.
- `GET /book/{slug}` — slug. This is what the SPA uses for detail pages.

Both return the same shape; pick based on what the caller has.

### Listing / searching

`GET /books` paginates (default 20, `?limit=` to override), sorts by primary author's last name, and accepts `?search=` (matches title or author name), `?format=` (filters by format name), and `?discarded=`.

`?discarded=` takes `exclude` (the default — hides books whose every version is discarded), `only` (just those books, i.e. the "Discarded" shelf), or `all` (no filtering). `0`/`false` alias to `exclude` and `1`/`true` to `only`; anything unrecognized falls back to `exclude`. The filter applies to the `?search=` branch too, so searching the library can't surface books the library itself won't show.

Response shape: `{ books, pagination: { total, perPage, currentPage, lastPage, from, to } }`. Items are run through `BookService::getBooksList`, which is the canonical "card-shaped" book payload — reuse it instead of building a parallel projection.

## Related

- Plan file: `/feature-plans/books.md` — future improvements and known limitations for this domain.
- `/documentation/lists.md` — lists hold versions (not books); the rating-doubling and user-scoping conventions documented here apply there too.
- `/documentation/authors.md`, `/documentation/genres.md`, `/documentation/formats.md` — taxonomy that books reference.
- `/documentation/read-history.md` — year-browse and completed-view aggregations built on `ReadInstance`.
- `/documentation/new-book-creation.md` — the multi-step creation flow summarized above.