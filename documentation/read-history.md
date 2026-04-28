---
path: /documentation/
status: living
---

# Read history

## Scope

Covers two surfaces built on `ReadInstance`: the per-book "add read history" flow (`/books/:slug/add-read-history` → `AddReadHistoryView`) and the year-browse "Completed" view (`/completed` → `CompletedView`). The `ReadInstance` model itself — schema, mutators, dual-attached FKs, the `auth()->id()` scoping convention — is owned by `books.md`; this doc links rather than restates. Read history captured *during* book creation is part of the new-book flow and lives in `new-book-creation.md`. Aggregated statistics (totals, ratings) are in `statistics.md` (planned).

## Summary

A `ReadInstance` is a per-user reading event with a `date_read` (optional) and `rating`. Two flows touch it after a book exists: a focused "add read history" form on each book page (single read at a time, attached to a chosen version), and a year-tabbed Completed view that lists every book the user finished in a given calendar year. Both are user-scoped — a `ReadInstance` belongs to exactly one user, and every read query in this surface filters by `auth()->id()`.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `POST /add-read-instance` → `BookController::addReadInstance` — record one read for an existing book + version.
  - `GET /completed/years` → `BookController::getCompletedYears` → `BookService::getAvailableYears()` — returns a sorted descending integer array of years the current user has reads in.
  - `GET /completed/{year}` → `BookController::getBooksByYear` → `BookService::getCompletedItemsForYear($year)` — every book the user has at least one read against in `$year`, with version + read-instance payloads scoped to that year.
- **Controllers**: `BookController` is thin for `getCompletedYears` and `getBooksByYear` (delegates to `BookService`). `addReadInstance` holds its own logic — it `findOrFail`s the book and version, instantiates a `ReadInstance` from the request, attaches `auth()->id()`, and then double-saves through both `$book->readInstances()` and `$version->readInstances()`.
- **Services**: `BookService::getAvailableYears()` and `BookService::getCompletedItemsForYear($year)`. The latter uses `whereHas('versions.readInstances', …)` to filter the book set, then re-runs the same year + user filter inside an eager-load on `versions.readInstances` and on the direct `readInstances` relation, then sorts the resulting collection in PHP by the first read instance's `date_read`. `transformCompletedBook` reshapes versions to a slim `{ version_id, page_count, audio_runtime, format: { format_id, name, slug }, readInstances }` payload.
- **Models**: `ReadInstance` (PK `read_instances_id`, fillable `user_id`, `book_id`, `version_id`, `date_read`, `rating`). Date mutator returns `Y-m-d`; rating mutator doubles input on write. See `books.md` for both.
- **Policies / authorization**: none. User scoping is enforced manually in every query via `where('user_id', auth()->id())`.
- **Migrations**: `2023_…_create_read_instances_table.php`. `date_read` is nullable; `book_id` and `version_id` are both NOT NULL FKs.

### Frontend

- **API layer**: `resources/js/api/BookController.js` — `getCompletedYears()` (`GET /api/completed/years`) and `getBooksByYear(year)` (`GET /api/completed/{year}`). There is **no** wrapper for `POST /add-read-instance` — `UpdateBookReadInstance.vue` calls `axios.post('/api/add-read-instance', …)` directly, bypassing the `api/` layer (see Non-obvious decisions).
- **Stores**: `BooksStore` (the book the form mutates) and `NewBookStore` (which doubles as a "current book being mutated" store; see `new-book-creation.md`). Year-browse data is held view-locally in `CompletedView.data()` (`loggedYears`, `activeYear`, `activeBooks`); no Pinia store backs it.
- **Service**: none dedicated. `services/BookServices.js::formatDateRead` is used elsewhere for display formatting; the read-history flow doesn't import it.
- **Routes**: `router/book-routes.js` defines `/books/:slug/add-read-history` (named `books.add-read-history`). `/completed` is defined directly in `router/index.js` (named `completed.home`).
- **Views**:
  - `views/AddReadHistoryView.vue` — version picker + form host. Auto-selects the only version when there's exactly one.
  - `views/CompletedView.vue` — year tabs across the top, `BookshelfTable` underneath.
- **Components**:
  - `components/updateBook/UpdateBookReadInstance.vue` — date + rating form, posts the read and refreshes `BooksStore`.
  - Reuses `components/books/table/BookshelfTable.vue` for the year listing.

## Non-obvious decisions and gotchas

- **`UpdateBookReadInstance` calls axios directly.** The codebase convention (`views → services/stores → api/<Domain>Controller → axios`) is enforced everywhere except here: the component imports `axios` and posts to `/api/add-read-instance` inline. Adding a `createReadInstance` wrapper to `api/BookController.js` is the right move; until then this is the one place to grep when the URL or payload shape changes.
- **The store is mutated before the API call.** `addReadInstanceToBookData` first calls `NewBookStore.addReadInstanceToExistingBookVersion`, *then* posts to the API. If the API fails (network drop, 500, validation), the in-memory store has the read instance but the database doesn't. The component logs the error and returns, but it does not roll back the optimistic update. The user can see a phantom read by navigating away.
- **`date_read` is optional, `rating` effectively isn't.** The form labels date as "(optional)" and converts blank → `null` on submit. Rating has no client-side validation but the `<select>`'s `required="false"` is implicit; submitting without a rating sends `""`, which the mutator doubles to `0` and stores as `0`. The "Makes read history optional" commit (e575417) only made *date* optional — rating remains a hidden required-by-default.
- **`addReadInstance` double-saves to set both FKs.** `$book->readInstances()->save($read_instance)` performs an INSERT with `book_id` set; `$version->readInstances()->save($read_instance)` then performs an UPDATE because the model already has a PK, this time setting `version_id`. The end state is a single row with both FKs populated, but at the cost of two queries. The `findOrFail` pair preceding it makes the inline `if (!$book || !$version)` check dead code (`findOrFail` would have thrown first).
- **Nothing enforces version-belongs-to-book.** `addReadInstance` accepts arbitrary `book_id` + `version_id` and writes them both; if a caller passes a version from a different book, the read instance silently links across books. The frontend always picks the version from `currentBook.versions`, but there's no server-side check.
- **`getCompletedItemsForYear` filters at three levels.** The book set is filtered by `whereHas('versions.readInstances', …)` on year + user. Inside the result, both `versions.readInstances` and the direct `readInstances` relation are *also* eager-loaded with the same year + user filter — so a book the user read in 2024 and 2026, fetched for 2024, will return only the 2024 reads in either relation. If you need full read history for the matched books, fetch the book separately via `BookService::getBookWithRelations`.
- **Sorting happens in PHP, not SQL.** `getCompletedItemsForYear` calls `->get()->map(...)->sortBy(...)->values()` — the entire matching set is hydrated and sorted by the first read instance's `date_read` after the fact. Fine at current volume; will not scale to thousands of reads per year. The sort key uses `$item['readInstances']->first()`, which is the *direct* book-level read instance (sorted ascending by `date_read` inside `transformCompletedBook`), not the version-level one.
- **`whereYear('date_read', $year)` is not index-friendly.** MySQL can't use an index on `date_read` when wrapped in `YEAR()`. Same for `selectRaw('YEAR(date_read) as year')` in `getAvailableYears`. Both will full-scan `read_instances` at scale. A range query (`date_read BETWEEN '$year-01-01' AND '$year-12-31'`) plus an index on `(user_id, date_read)` would fix this.
- **`getAvailableYears` uses MySQL-specific `YEAR()`.** Portable to other databases would require `EXTRACT(YEAR FROM date_read)` or similar. Tracked in the plan.
- **`getBooksByYear($year)` does not validate the path param.** The route segment is captured as a string and passed straight into `whereYear`. Eloquent's bound parameter keeps this safe from injection, but `/completed/abc` returns `[]` rather than 404 — which the SPA never hits because tabs come from `getAvailableYears`.
- **`CompletedView` keeps state view-local.** No Pinia store. Switching years refetches every time (no caching), and navigating away from `/completed` and back refetches `loggedYears` from scratch.
- **Years are populated only from non-null `date_read`.** Reads with no date are absent from `getAvailableYears` and from any `getCompletedItemsForYear` result (since `whereYear` rejects nulls). A user who logs an undated read sees it on the book detail page but never in `/completed` — the book is "completed" but lives nowhere on the year-browse surface.
- **`AddReadHistoryView` reads from `BooksStore.allBooks`, falls back to a fetch.** If the user lands on the page directly (no library visit yet), the view fetches the book, calls `BooksStore.addBook(book.data)`, then proceeds. The form submit later overwrites the book in `allBooks` by index — `BooksStore.allBooks[bookIndex] = NewBookStore.currentBookData`. This couples the two stores: any divergence in shape between what `BooksStore` holds elsewhere and what `NewBookStore.currentBookData` produces will silently break list rendering.
- **`AddReadHistoryView` auto-selects the only version, but offers no submit UI when no version is selected.** The form (`UpdateBookReadInstance`) always renders, but `selectedVersion` is `required: true` on the prop — Vue will warn in dev when the prop is missing. There's no visible guard preventing the user from filling out the form without selecting a version.
- **The version cards in `AddReadHistoryView` aren't clickable.** They highlight when selected (`selectedVersion?.version_id` matches), but there's no `@click` on the card to set it. With more than one version, `selectedVersion` is null and stays null — the user has no way to advance. (Today most books have one version, masking the bug.)
- **Rating mutator doubles, dates serialize as `Y-m-d`.** Both inherited from the `ReadInstance` model and documented in `books.md`. Display code must halve `rating`; date strings round-trip without parsing. JS `MM/DD/YYYY` UI input is sent as-is — `addReadInstance` does not normalize, so the column stores whatever the client sent. (Today, `UpdateBookReadInstance`'s regex enforces `MM/DD/YYYY`, but Eloquent's `protected $dates = ['date_read']` plus the column being a `date` type means MySQL parses `MM/DD/YYYY` as the column's date format on insert — verify before changing the input format.)

## Usage notes

### Recording a read

`POST /api/add-read-instance` body:

```
{
  readInstance: {
    book_id,
    version_id,
    date_read,   // 'MM/DD/YYYY' from the form, or null when blank
    rating       // 1–5 in 0.5 steps; doubled by the mutator on write
  }
}
```

`user_id` is taken from the session. Response is the saved `ReadInstance` (rating already doubled). HTTP 200 on success; the controller returns 404 only on the dead `if (!$book || !$version)` branch (in practice you'll get a `ModelNotFoundException` 404 from `findOrFail` first).

### Listing years

`GET /api/completed/years` returns `[2026, 2025, 2024, …]` — sorted descending integer years where the user has at least one `date_read`-having read.

### Year browse

`GET /api/completed/{year}` returns an array of:

```
{
  book: { book_id, title, slug },
  authors: [...],
  versions: [{ version_id, page_count, audio_runtime, format: { format_id, name, slug }, readInstances: [...] }],
  genres: [...],
  readInstances: [...]   // direct book-level relation, year-filtered
}
```

Sorted ascending by the first (earliest in the year) read instance's `date_read`. Books with no read in the year are excluded.

## Related

- Plan file: `/feature-plans/read-history.md` — known limitations and future improvements.
- `/documentation/books.md` — `ReadInstance` schema, dual-attached FKs, rating mutator, date serialization, and the user-scoping convention.
- `/documentation/new-book-creation.md` — read-history captured at book-create time (pushed into `currentBookData.read_instances` and persisted alongside the book in `POST /create-book`).
- `/documentation/statistics.md` (planned) — aggregate metrics derived from `ReadInstance`.
