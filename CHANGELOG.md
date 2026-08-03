# Changelog

All notable changes to Pergamum will be documented in this file.

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
