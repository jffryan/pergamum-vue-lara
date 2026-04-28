---
path: /documentation/
status: living
---

# Bulk upload

## Scope

Covers the CSV-based bulk import (`/bulk-upload` → `BulkUploadView` → `POST /api/bulk-upload` → `BulkUploadController::upload`). Single-book creation through the SPA is in `new-book-creation.md`; this surface is positional-CSV-only and shares almost no code with the new-book flow.

## Summary

A single endpoint that accepts a CSV file and processes it row-by-row, each row in its own transaction. Each row creates one book, one author, one version, optionally one genre list and optionally one read instance. Rows that fail validation or violate a rule (missing required field, unknown format, slug collision, malformed date) are reported in the response but don't stop later rows. The frontend renders a per-row results table grouped by status (success / skipped / failed).

## How it's wired

### Backend

- **Route** (`routes/api.php`, `auth:sanctum`): `POST /bulk-upload` → `BulkUploadController::upload`. Accepts a multipart form with field `csv_file`.
- **Controller**: `BulkUploadController` — single `upload` method. Not thin: holds the CSV parsing loop, slug normalization, format lookup, dedupe check, per-row transaction, and result aggregation directly.
- **Services**: none. Does not call `BookService`, `AuthorService`, or `NewBookController`. Each row's writes are inline `Eloquent` calls.
- **Models**: creates `Book`, `Version`, `ReadInstance`; `firstOrCreate`s `Author` and `Genre`. `Format` is read-only (existing rows only).
- **Policies / authorization**: none beyond `auth:sanctum`.
- **Migrations**: writes the same tables documented in `books.md`, `authors.md`, `genres.md`, `formats.md`. No bulk-upload-specific schema.

### Frontend

- **API layer**: `resources/js/api/BulkUploadApi.js` — `bulkUpload(file)` builds a `FormData`, sets `Content-Type: multipart/form-data`, and calls `axios.post('/api/bulk-upload', …)` directly. Does **not** route through `apiHelpers.js` (`makeRequest` / `buildUrl`) — multipart bodies don't fit `makeRequest`'s JSON shape, so this is the one acceptable bypass.
- **Stores**: none. State lives view-locally in `BulkUploadView.data()` (`selectedFile`, `loading`, `summary`, `results`, `error`).
- **Service**: none.
- **Routes**: `router/book-routes.js` defines `/bulk-upload` (named `books.bulk-upload`).
- **Views**: `views/BulkUploadView.vue` — file input, submit button, a per-row results table colored by status.
- **Components**: none — the view is self-contained.

## Non-obvious decisions and gotchas

- **Per-row transactions, not whole-file.** Each row runs `DB::beginTransaction` / `DB::commit`; a failing row rolls back its own writes only and the loop continues. There is no "all-or-nothing" mode — partial imports are the design. Callers must inspect `summary` and `results` to decide what to do; a 200 response can mask a file where every row failed.
- **CSV is positional, not column-named.** Columns are read by index 0–7: `[Title, Author_FNAME, Author_LNAME, Version_Format, Version_PageCount, Date_Read, Rating, Genres]`. The header row is skipped (`fgetcsv($handle)` once before the loop) but its content is *not* validated — a CSV with the wrong column order is accepted, and rows are silently mapped to the wrong fields. The header is decorative; mismatching it doesn't cause an error.
- **Required fields are Title, Version_Format, Version_PageCount, and at least one of Author_FNAME / Author_LNAME.** Everything else (Date_Read, Rating, Genres) is optional. A row with only the required four creates a book + author + version with no genres and no read history.
- **Format lookup is case-insensitive via `whereRaw('LOWER(name) = ?', …)`.** Non-index-friendly at scale, but the `formats` table has fewer than a dozen rows in practice. If the format doesn't match a known row the row is *failed* — bulk upload does not create new formats (unlike authors and genres, which it firstOrCreates).
- **Slug collision is the dedupe.** Each row computes `Str::lower → strip non-alphanumerics → spaces-to-hyphens → cap at 50` (matches the slug rule used by `BookController::store` and `NewBookController::createOrGetBookByTitle`). Rows whose slug already exists in `books.slug` are reported as `skipped` with reason `'Book already exists'`. The dedupe runs *before* the transaction, so a concurrent upload of the same title can race past the check; only `Book::create` would catch it, and there's no DB-level unique constraint on `slug` to backstop. In practice bulk uploads are user-serial.
- **Yet another author slug normalizer.** `BulkUploadController` builds the author slug as `str_replace(' ', '-', preg_replace('/[^a-z0-9\s]/', '', strtolower(implode(' ', [fname, lname]))))` — strips non-alphanumerics, no length cap, **does not collapse internal whitespace**. This is the *fourth* distinct rule in the codebase (alongside `BookController::handleAuthors`, `NewBookController::handleAuthors`, and `AuthorController::getOrSetToBeCreatedAuthorsByName`; see `authors.md` for the full list). An author imported via bulk upload and later re-encountered via the new-book flow can fail to dedupe.
- **`Author::firstOrCreate` matches on slug only.** Match attributes are `['slug' => $authorSlug]`; first/last names are passed only as create-time values. If an existing author shares the slug but has a different name (typo, missing accent, different first-name spelling), the import attaches to the existing row and the names in the CSV are silently ignored. Be cautious importing a corrected version of an author you already have.
- **One author per row.** No multi-author support in the CSV format. A two-author book imported through this surface gets only the row's `(fname, lname)` attached; the second author has to be added via the book-edit flow afterward. There's no warning column for "this book has co-authors".
- **One version per row.** Each row creates one `Version` with `(book_id, format_id, page_count)`. `audio_runtime` and `nickname` are not in the CSV format and are persisted as `null`. Importing the same book in two formats requires two rows — and the second row will be `skipped` because the slug collides with the first. Multi-version books cannot be created through bulk upload; add additional versions afterward via `AddVersionView`.
- **Date format is hardcoded to `n/j/Y`.** Carbon's `n/j/Y` accepts `1/5/2024` *and* `01/05/2024` (single- or zero-padded month/day, four-digit year). Anything else (`2024-01-05`, `Jan 5, 2024`, `5/1/24`) throws a `Carbon\Exceptions\InvalidFormatException`, which the row's catch block reports as the failure reason. The error message is the raw Carbon string — not user-friendly.
- **Rating is cast `(float)` with no validation.** `$rating !== '' ? (float) $rating : null`. A non-numeric rating coerces to `0` (PHP `(float) 'abc' === 0.0`); `7` is accepted as-is and stored as `14` after the doubling mutator. Out-of-range values are not flagged.
- **The mutator doubles ratings on insert.** Same as everywhere else (see `books.md`). A CSV value of `4.5` is stored as `9`; the bulk import doesn't pre-divide.
- **Read instance is created only when `Date_Read` is non-empty.** A row with `Rating` set but `Date_Read` blank does *not* create a `ReadInstance` — the rating is silently dropped. There's no warning. Conversely a row with `Date_Read` set and `Rating` blank creates an instance with `rating = null`.
- **Genres split on `,` after the row split.** `fgetcsv` already split the CSV on `,`, so genres live in column index 7 in their *original* unsplit form — the genres column itself must be quoted in the CSV (`"sci-fi, dystopia"`) for `explode(',', $genresRaw)` to find more than one. Otherwise commas in the genre column become column separators and shift everything right. The current convention requires CSV producers to quote multi-genre cells.
- **Empty genre names are filtered.** `trim($genreName) !== ''` guards `Genre::firstOrCreate`. Trailing commas in the genre column are fine.
- **Empty rows are skipped silently.** A row where every column trims to empty does not increment any counter — it's just skipped. `$rowNumber` still advances, so the result table can show non-contiguous row numbers.
- **`$rowNumber` is data-row-relative, not file-relative.** It starts at 0 and increments after the header row is consumed. A "row 5" in the response is the 5th data row, which is line 6 of the file. Cross-reference with the original CSV accordingly.
- **Failures expose `$e->getMessage()` directly.** The catch block puts the raw exception message into the row's `reason` field. A SQL constraint violation surfaces with whatever the driver chose to say — useful for debugging, but not safe to rely on as a stable contract for callers.
- **No file size or row count limit.** The route inherits PHP's `upload_max_filesize` and `post_max_size`. There's no in-app guard; a multi-megabyte CSV will run to completion (or until PHP's max execution time kicks in).
- **No async / job queue.** The request runs synchronously and blocks until the whole file is processed. A user uploading thousands of rows pays the full latency in one HTTP call.

## Usage notes

### Required CSV format

8 columns, in order, with a header row (header content is unchecked):

```
Title,Author_FNAME,Author_LNAME,Version_Format,Version_PageCount,Date_Read,Rating,Genres
Dune,Frank,Herbert,Hardcover,688,8/1/2023,4.5,"sci-fi, classic"
The Hobbit,J.R.R.,Tolkien,Paperback,310,,,fantasy
A Memory Called Empire,Arkady,Martine,Audiobook,464,3/15/2024,5,
```

- `Title`, `Version_Format`, `Version_PageCount`, and at least one of `Author_FNAME` / `Author_LNAME` are required.
- `Version_Format` must be the case-insensitive `name` of an existing row in `formats` (e.g. `Hardcover`, `Paperback`, `Audiobook`). Unknown formats fail the row.
- `Date_Read` accepts `n/j/Y` (`1/5/2024` or `01/05/2024`). Blank → no read instance is created.
- `Rating` is a float; the mutator doubles it on insert. Blank → `rating = null` on the read instance.
- `Genres` is a comma-separated list; quote the cell if you have more than one (`"a, b, c"`).

### Submitting

`POST /api/bulk-upload` multipart with `csv_file`. Response:

```json
{
  "summary": { "total": 12, "succeeded": 9, "skipped": 2, "failed": 1 },
  "results": [
    { "row": 1, "title": "Dune", "status": "success" },
    { "row": 2, "title": "The Hobbit", "status": "skipped", "reason": "Book already exists" },
    { "row": 3, "title": "Spice", "status": "failed", "reason": "Format 'eBook' not found" }
  ]
}
```

Always HTTP 200, even when every row fails. The 200 body is what the SPA renders into its results table.

### Failure modes the user will see

- `'Title, Version_Format, and Version_PageCount are required'` — empty required fields.
- `'At least one of Author_FNAME or Author_LNAME is required'` — both name fields blank.
- `'Format '<X>' not found'` — format name doesn't match any existing row, case-insensitively.
- `'Book already exists'` — slug collision with an existing book. Reported as `skipped`, not `failed`.
- Carbon parse error string — `Date_Read` not in `n/j/Y`.
- Raw SQL / Eloquent exception messages — anything else that throws inside the row's transaction.

## Related

- Plan file: `/feature-plans/bulk-upload.md` — known limitations and future improvements.
- `/documentation/books.md` — `Book` / `Version` / `ReadInstance` schema, custom PKs, rating mutator, slug rules.
- `/documentation/new-book-creation.md` — the interactive creation flow this surface bypasses entirely. The two paths share no code; behavior may drift.
- `/documentation/authors.md` — the four divergent author slug normalizers, of which this controller has the fourth.
- `/documentation/genres.md`, `/documentation/formats.md` — taxonomy attached / matched per row.
