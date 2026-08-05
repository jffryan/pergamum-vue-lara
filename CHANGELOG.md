# Changelog

All notable changes to Pergamum will be documented in this file.

## [0.1.8] - 2026-08-05

- **Read history is user-scoped by the model rather than by every reader.** `App\Models\Scopes\BelongsToCurrentUser` is a global scope on `ReadInstance`: `where('read_instances.user_id', auth()->id())` is now what happens unless a caller opts out by name. It replaces fifteen hand-written predicates across `BookController`, `BookService`, `ListController` and the statistics layer.
- **Fixed: the author and genre detail pages leaked read history across accounts.** `AuthorService::getAuthorWithRelations` and `GenreController::show` both eager-loaded `books.readInstances` with no user filter — the two readers that forgot the predicate every other reader remembered. Both are closed by the scope, and pinned by `tests/Feature/UserScoping/TaxonomyScopingTest`.
- Querying `ReadInstance` with no authenticated session now throws instead of guessing. Matching nothing would make a console context look like an empty library and matching everything would silently undo the scope; a caller that means it writes `withoutGlobalScope(BelongsToCurrentUser::class)`.
- Three call sites lift the scope deliberately: `BookController::destroy` (deleting a book must remove *every* account's reads of it, or they orphan against a deleted `book_id`), and `ReadInstanceQuery::forScope` / `TotalBooksRead` (statistics answer for the scope's subject, not the session — so an admin scope reporting on another account still works).
- `BookList` deliberately did **not** get the scope. It would run before `BookListPolicy`, so route model binding would fail to resolve another user's list and every documented 403 would become a 404.
- **New: `GET /api/export` returns the catalog as a CSV the bulk importer reads back.** Streamed, one row per (version, read), with a "Download export" button on `/bulk-upload`. The catalog crosses whole; read history and lists are the requesting user's only. `rating` is halved back to the 0–5 display scale, without which a roundtrip would double it on every pass.
- **The CSV contract gained three optional columns** — `is_discarded`, `discarded_at`, and `lists` (`;`-separated `Name|ordinal`). Every file that was valid before still is. Discard state and list membership were both silently lost by a database reset: the first has been un-roundtrippable since discarding shipped in 0.1.3, and the second since lists existed. The ordinal travels in the cell because row order cannot carry it — a version on two lists sits at a different position in each.
- `App\Support\CsvContract` holds the column vocabulary for both the reader and the writer, so a column added to one and forgotten by the other can't quietly stop surviving a reset. `tests/Feature/Export/CatalogRoundtripTest` exports a catalog exercising every column, wipes everything but formats and users, re-imports, and asserts equality.
- Retired `/feature-plans/reset-database.md`. Its runbook is now `/documentation/database-reset.md`, its export wish is built, and both of its known limitations are gone: lists roundtrip, and `Book.nickname` turned out never to have existed as a column — only `versions.nickname` does. Same class of error as the `date_completed` accessor removed in 0.1.7.

## [0.1.7] - 2026-08-04

- The book write surface validates its input. `BookController` and `NewBookController` called `validate()` zero times between them and reached into payloads by key, so a missing key was an `Undefined array key` — a 500 that told the caller nothing. Every write endpoint now takes a `FormRequest` under `app/Http/Requests/`: `StoreBookRequest`, `UpdateBookRequest`, `StoreReadInstanceRequest`, `StoreVersionRequest`, `CreateBookTitleRequest`, `CompleteBookCreationRequest`, plus `StoreListRequest`, `UpdateListRequest`, `StoreListItemRequest` and `ReorderListRequest`. They share `ApiFormRequest`, which keeps the API's `reason_code` on a 422 now that the checks are validation rules rather than hand-rolled branches.
- **Breaking: `PUT|PATCH /api/books/{id}` takes a flat payload.** `book`, `authors`, `genres`, `versions` and `readInstances` are top-level keys; the `{ request: { formData: … } }` envelope is gone.
- **Breaking: a bad field in a request body is a 422, not a 404.** `POST /add-read-instance` with an unknown `book_id` or `version_id` used to `findOrFail` into a 404. 404 now means the URL's resource is missing.
- **Breaking: `POST /api/create-book` reports failure with a failure status.** A malformed payload is a 422; an unexpected error is a 500. It used to answer `200 { success: false }`, which a caller had to read the body to notice.
- **Fixed: an unknown format id was silently swallowed on two paths.** `prepareVersions` hit `continue` on a format it couldn't find, so `POST /books` with a typo'd format answered 200 and created a book with no copies; `updateVersions` did the same, so the edit reported success and changed nothing. Both are now a 422 naming the field.
- **Fixed: `POST /api/versions` and `POST /api/create-book` ignored format capability flags.** Only book create and book edit ever applied them, so those two paths could store a page count on an audiobook or a runtime on a paperback — the exact orphan values `estimatedTotalPagesByYear` and `audioRuntimeByYear` double-count. The reduction moved from a private controller method to `Format::lengthFieldsFrom()` and all four writers share it.
- **Fixed: a `date_read` in `m/d/Y` was a 500.** The controllers called `Carbon::createFromFormat('Y-m-d', …)`, which throws on the display format the edit form round-trips. Dates normalize to `Y-m-d` at the request boundary and controllers no longer parse them.
- `POST /add-read-instance` is one insert in a transaction instead of a save through both the book and the version relation, closing the window where the first had landed and the second hadn't.
- Reorder rejections name the offending list-item ids — both the ones that aren't in the list and the ones left out of the payload. Reordering a list you don't own is now a 403; the membership check used to run first and answer 422, which was itself an answer about a list you can't see.
- The book update path and `completeBookCreation` log their exception message and return a generic one instead of putting it in the response body.
- `Book::$fillable` no longer lists `date_completed`, and `getDateCompletedAttribute` is gone. No such column has ever existed; the accessor could only ever return null. Read history is the source of truth for when a book was finished.
- Decided: Pergamum stays a **shared catalog with per-user reading state**. At most one further account is expected and it belongs to the same household, so books, versions, authors and genres stay global and only `ReadInstance` and `BookList` are user-scoped. The "no per-user ownership" entries across six feature plans are closed as deliberate rather than outstanding. Correct `auth()->id()` scoping on reads and lists still matters — that is the only thing separating the two users.

## [0.1.6] - 2026-08-02

- Formats now declare what they are measured in. `formats.expects_page_count` and `formats.expects_audio_runtime` replace every way the app used to identify a medium — `$format->name == 'Audiobook'` / `'Paper'` in `BookController`, `strcasecmp(…, 'Audiobook')` in the bulk importer, `format?.name === "Audiobook"` in two Vue files, and a hardcoded `format_id === 2` in three more. Adding a medium with different length semantics is now a row in `formats`, not a branch. `GET /api/config/formats` carries the two flags, `POST /api/formats` accepts them (defaulting to a print format), and the SPA reads them through `resources/js/utils/formats.js`.
- **Fixed: creating or editing a version of any format other than Audiobook could 500.** The old `'Paper'` branch never matched anything — the format is named "Physical" — so every non-audio format fell through to a branch that read `audio_runtime` unguarded, and a payload omitting it threw `Undefined array key`.
- **Fixed: an audiobook could not be stored without a page count.** `versions.page_count` was `NOT NULL`, so a null page count was an integrity-constraint violation and the importer wrote `0` to work around it. The column is nullable, and a format that carries no page count now stores none. Both read as zero to every `SUM`, so no statistic changed.
- **Fixed: `NewVersionsInput` required a page count for audiobooks**, whose page-count input it doesn't render — an error with nothing on screen to clear it. Validation now follows the format's capabilities, on both the new-version and create/edit forms.
- A length value the format doesn't carry is nulled on write rather than passed through, on every path. Re-formatting an audiobook as physical clears its runtime instead of leaving an orphan that `audioRuntimeByYear` still sums, and an import row can't smuggle a page count onto an audiobook that `estimatedTotalPagesByYear` would then count twice.
- `database/seeders/FormatSeeder.php` (wired into `DatabaseSeeder`) seeds the canonical formats with their capability flags, so `migrate:fresh --seed` produces a database the bulk importer can write to. It `updateOrCreate`s on name, so it also repairs an existing database's flags. This unblocks `/feature-plans/reset-database.md`, whose two largest footgun sections are now moot.
- `ConfigStore.createFormat` refetches the format list instead of pushing the POST response onto it — the two shapes differ, and the forms now read capability flags off whatever is cached.
- Admin format creation gained checkboxes for the two capabilities.

## [0.1.5] - 2026-08-02

- Statistics are now a scoped metric registry (`app/Statistics/`) behind one endpoint: `GET /api/statistics/{scope?}/{scopeId?}`, with `scope` defaulting to `user`, so `GET /api/statistics` still resolves. `?metrics=` takes a comma-separated subset; an unknown key for the scope is a 422, an unknown scope a 404, someone else's list a 403. **Breaking:** the response is now `{ scope, metrics, meta }` with camelCase metric keys throughout — `total_books` → `totalBooks`, `total_books_read` → `totalBooksRead`, `totalPagesByYear` → `pagesReadByYear`, and `booksReadByYear` → `readsByYear`. `App\Services\StatisticsService` is deleted.
- `meta` describes the response's own caveats from declarations on each metric: `catalogWide` (ignores user scoping), `shelfScoped` (excludes fully-discarded books), `estimated` (derived, with the conversion factors echoed), and `failed`. A metric that throws is logged and listed in `meta.failed` instead of 500-ing the whole surface.
- New metrics: `totalReads` (counts undated reads, which the dashboard's client-side sum silently dropped), `uniqueBooksReadByYear`, `averageRating` and `ratingDistribution` (halved to the 0–5 display scale server-side), `audioRuntimeByYear` (minutes), and `estimatedTotalPagesByYear`, which folds listening into pages at a rate configured in `config/statistics.php`. `readsByYear` keeps the old `COUNT(*)` semantics under a label that no longer claims to count books.
- List statistics moved server-side as a `list` scope — `totalItems`, `completedCount`, `completedPercent`, `totalPages`, `genreBreakdown`, `averageRating`, `ratingDistribution`. Every number matches what `ListStatisticsView` used to derive client-side, verified against real data.
- `newestBooks` now excludes books whose every copy is discarded; `totalBooks` deliberately does not, because it is the denominator of `percentageOfBooksRead` and the numerator counts discarded books. The "a book is discarded only when every version is" rule moved from a private controller method to `Book::scopeOnShelf()` / `Book::scopeFullyDiscarded()`.
- `percentageOfBooksRead` no longer re-runs the two counts it depends on.
- Frontend: statistics surfaces are config objects rendered by `StatisticsGrid` over a widget registry. `StatisticsDashboard` and the new `/dashboard` summary are one line each; `/dashboard` stops being a placeholder. A `StatisticsStore` caches per scope and requests only uncached metrics, so `/dashboard` → `/statistics` fetches the delta. Loading, error-with-retry, and empty states now exist on every statistics surface, and `ListStatisticsView` reads the list from `ListsStore` instead of re-fetching it.
- Fixed the `read_instances ⋈ versions` join in the per-year page count, which matched on `version_id` alone and so credited a mismatched historical row to the wrong book's page count.
- Fixed read-history edits silently doing nothing. `ReadInstance::$primaryKey` was `read_instances_id`; the column has always been `read_instance_id`. `PUT /api/books/{id}` filtered incoming read instances on the misspelled key, so every row was discarded before the update ran — changing a rating or a date on the book edit page appeared to save and changed nothing. The model, `BookController::updateReadInstances`, and the edit form now all use `read_instance_id`. Newly created read instances also stop carrying a bogus `read_instances_id` in API responses; no stored data changes and no migration is needed.

## [0.1.4] - 2026-08-02

- Bulk upload now derives book and author slugs with the shared `App\Support\Slugger` helper and validates ratings with `App\Support\RatingValidator`, matching every other creation path. Existing book and author slugs are normalized to the same rule by migration, so a book imported from CSV and the same book created in the SPA are one row rather than two. Some book and author detail URLs change as a result.
- Bulk upload's whole-file rejection messages (missing column, unknown column, duplicate column) now render in the UI instead of a generic "An error occurred during upload."
- Bulk upload can file an entire import into a brand-new list. `POST /api/bulk-upload` accepts an optional `list_name`; every version a successful row found or created is appended to a new list owned by the uploader, in CSV row order. The response grows a `list` block. The list is created lazily, so an import where every row fails leaves nothing behind and the name stays free for a retry. A name already taken by one of your lists rejects the whole upload with `422 list_name_taken` before anything is imported. `BulkUploadView` gains an "add to a new list" checkbox.
- Bulk upload's CSV header now requires only `title`, `authors`, and `format`. Every other column may be omitted, so a file of nothing but paperbacks no longer has to carry an empty `audio_runtime` column. Per-row value requirements are unchanged — a paperback row with no page count still fails with `page_count_required`. Every file that was valid before is still valid.

## [0.1.3] - 2026-08-02

- Copies can be marked as discarded — books we used to own but no longer do. Recorded on the version as `versions.is_discarded` plus an optional `versions.discarded_at` date, so the copy keeps its format and its read history. A null date means "discarded, date unknown", which is the expected case for anything got rid of before tracking started. Discarded is deliberately *not* a new `Format`; format stays the medium.
- New endpoints `PATCH /api/versions/{version}/discard` (optional `{ discarded_at }` body) and `PATCH /api/versions/{version}/restore`.
- `GET /api/books` accepts `?discarded=exclude|only|all`, defaulting to `exclude`. A book drops off the shelf only when *every* version is discarded. The filter applies to the `?search=` branch too, so the library and its search agree.
- The library gains an "On the shelf" / "Discarded" toggle; the book detail page's version table gains a Status column with discard/restore controls.
- Fixed unsafe `orWhere` precedence in the books search query — appended filters previously bound to the last OR term only.

## [0.1.2] - 2026-04-29

- Bulk upload rewritten with a header-named CSV contract (`title, authors, format, page_count, audio_runtime, version_nickname, genres, date_read, rating`). Multi-author, multi-version, re-read, and audiobook rows are supported; per-row failures carry a `reason_code`; a `dry_run=1` flag previews without writing.
- Slugs for books and authors are now generated by a single shared helper. New slugs cap at 60 characters and truncate at a hyphen boundary. Existing slugs are not backfilled.
- `POST /api/add-read-instance` and `POST /api/create-book` now reject ratings outside 0.5–5 (in 0.5 steps), matching bulk upload. `add-read-instance` returns HTTP 422 with `reason_code: rating_out_of_range`; `create-book` rolls back and returns `success: false` with a rating-mentioning message.
- `POST /api/create-book` no longer leaks stack traces in failure responses.

## [0.1.1] - 2026-04-28

- Upgraded Laravel from 9 to 10. PHP minimum is now 8.1.

## [0.1.0] - 2026-04-26

Baseline snapshot of the app prior to formal changelog tracking. Earlier history is captured in git only.

- Laravel 9 / PHP 8 JSON API backed by MySQL 8, with Sanctum auth.
- Vue 3 + Pinia + Vue Router SPA served from a single Blade entrypoint.
- Core domain: books, authors, genres, formats, versions, read instances, and lists.
- Book/author/format detail pages with slug-based URLs and mobile-responsive layouts.
- Reading history tracking with ratings and read dates, plus related-books-by-author surfacing.
- User-curated lists with list items keyed to versions, and list statistics.
- Year-browse / completed views and statistics views.
- Dockerized dev environment.
