---
path: /documentation/
status: living
---

# Bulk upload

## Scope

Covers the CSV-based bulk import (`/bulk-upload` → `BulkUploadView` → `POST /api/bulk-upload` → `BulkUploadController::upload` → `BulkImportService::importCsv`). Single-book creation through the SPA is in `new-book-creation.md`; this surface is CSV-only and shares almost no code with the new-book flow.

## Summary

A single endpoint that accepts a CSV file and processes it row-by-row. Each row creates or finds one book, attaches one or more authors, optionally attaches genres, creates or finds one version, and optionally creates one read instance. Rows that fail validation are reported in the response but don't stop later rows. The frontend renders a per-row results table.

Whole-file errors (header validation) return HTTP 422 with a `reason_code`. Per-row failures still return HTTP 200 with the row marked failed in the results array.

## How it's wired

### Backend

- **Route** (`routes/api.php`, `auth:sanctum`): `POST /bulk-upload` → `BulkUploadController::upload`. Accepts a multipart form with field `csv_file` and an optional `dry_run` boolean.
- **Controller**: `BulkUploadController` — thin handler. Validates the file, calls `BulkImportService`, catches `BulkImportHeaderException` for the 422 path.
- **Service**: `app/Services/BulkImportService.php` — owns parsing, header validation, per-row processing, transactions, and result aggregation. Public surface is `importCsv(UploadedFile $file, int $userId, bool $dryRun = false): array`.
- **Models**: creates `Book`, `Version`, `ReadInstance`; finds-or-creates `Author` and `Genre`. `Format` is read-only (existing rows only — no auto-create).
- **Policies / authorization**: none beyond `auth:sanctum`.
- **Migrations**: writes the same tables documented in `books.md`, `authors.md`, `genres.md`, `formats.md`. No bulk-upload-specific schema.

### Frontend

- **API layer**: `resources/js/api/BulkUploadApi.js` — `bulkUpload(file)` builds a `FormData`, sets `Content-Type: multipart/form-data`, and calls `axios.post('/api/bulk-upload', …)` directly. Does **not** route through `apiHelpers.js` (`makeRequest` / `buildUrl`) — multipart bodies don't fit `makeRequest`'s JSON shape, so this is the one acceptable bypass.
- **Stores**: none. State lives view-locally in `BulkUploadView.data()`.
- **Routes**: `router/book-routes.js` defines `/bulk-upload` (named `books.bulk-upload`).
- **Views**: `views/BulkUploadView.vue` — file input, submit button, a per-row results table colored by status. Renders `reason` (human string) when a row fails.

## CSV contract

### Columns

Header is **required and validated by name**. Column order is irrelevant. Header comparison is case-insensitive and trims whitespace. Unknown column names are rejected (`reason_code: header_invalid`). Missing required columns are rejected the same way. The order in which the columns appear in your file does not matter; only the names do.

| Column            | Required | Notes |
|-------------------|----------|-------|
| `title`           | yes      | Used to derive `Book.slug` (`Str::slug`, no truncation). |
| `authors`         | yes      | `;`-separated list of entries; each entry is `First\|Last`. Single-author rows use one entry. Empty `First` or empty `Last` are allowed (one of the two must be non-empty per entry). |
| `format`          | yes      | Looked up case-insensitively in `formats.name`. Must already exist; bulk upload does not auto-create formats. |
| `page_count`      | yes for non-audio | Integer. Blank is allowed for `Audiobook` rows; the version is stored with `page_count = 0` in that case. |
| `audio_runtime`   | yes for `Audiobook` rows | Integer minutes. Required for Audiobook rows; blank otherwise. |
| `version_nickname`| no       | Free text; disambiguates versions sharing `(book, format)` (e.g., two paperbacks). |
| `genres`          | no       | `;`-separated list. Lookup is case-insensitive and trims whitespace; `Fantasy`, `fantasy`, ` Fantasy ` all dedupe to the existing genre. |
| `date_read`       | no       | Accepts `Y-m-d`, `n/j/Y`, `m/d/Y`. Blank means "no read instance for this row." |
| `rating`          | no       | Decimal 0.5–5 in 0.5 steps. The `ReadInstance` mutator doubles the value on insert (a CSV value of `4.5` is stored as `9`). |

### Encoding choices

- `;` between list entries, `|` between author name parts. Avoids CSV-comma-quoting traps.
- One `authors` column rather than separate first/last columns — symmetric for single-author and multi-author rows.

### Row semantics

Each row describes one (book, version, optional read instance). Rows are de-duped against existing rows at each layer:

1. **Book**: find-or-create by `slug = Str::slug(title)`. Title on existing books is left alone.
2. **Authors**: each entry → find-or-create by `Str::slug(trim($first.' '.$last))`. Attached if not already attached. Co-author ordinal continues from the book's current max.
3. **Genres**: each entry → find by `LOWER(TRIM(name))` first; if none, create with the trimmed (case-preserved) value. Attached if not already attached.
4. **Version**: find-or-create by `(book_id, format_id, version_nickname)`. `audio_runtime` and `page_count` are written on create; on existing-version match they are left alone (so re-imports don't overwrite hand edits).
5. **Read instance**: if `date_read` is non-blank, always create a new `ReadInstance` against the resolved version with `user_id = auth()->id()`. Multiple rows with the same (title, format, nickname) but different dates produce multiple read instances — re-reads roundtrip cleanly.

This single-row shape covers every restore scenario:

- One-time read of a paperback: one row.
- Re-read of the same paperback three times: three rows, identical except `date_read` / `rating`.
- Same book in paperback and audiobook: two rows, different `format` (and `audio_runtime` on the audiobook row).
- Owned but unread: one row with blank `date_read` and `rating`.
- Two co-authors: one row, `authors = "Jane|Smith;John|Doe"`.

## Submitting

`POST /api/bulk-upload` multipart with `csv_file` and an optional `dry_run` boolean.

Successful (or partially-successful) response, HTTP 200:

```json
{
  "summary": { "total": 12, "succeeded": 10, "skipped": 0, "failed": 2 },
  "results": [
    { "row": 1, "title": "Dune", "status": "success" },
    { "row": 2, "title": "Lost", "status": "failed", "reason_code": "format_not_found", "reason": "format 'eBook' not found" }
  ],
  "dry_run": false
}
```

Header-invalid response, HTTP 422:

```json
{ "reason_code": "header_invalid", "reason": "Missing required column(s): authors" }
```

`summary.skipped` is always `0` in the new contract — finer-grained idempotency reporting (rows that produced zero new writes) is tracked as a future improvement on `/feature-plans/bulk-upload.md`. Re-uploading a CSV simply reports each row as `success` while reusing existing books / authors / versions.

### Per-row `reason_code` values

| Code                        | Meaning |
|-----------------------------|---------|
| `missing_required_field`    | `title`, `authors`, or `format` was blank. |
| `format_not_found`          | `format` did not match any row in `formats` (case-insensitive). |
| `audio_runtime_required`    | `format = Audiobook` row had a blank `audio_runtime`. |
| `page_count_required`       | Non-audio row had a blank `page_count`. |
| `date_parse_failed`         | `date_read` did not match `Y-m-d`, `n/j/Y`, or `m/d/Y`. |
| `rating_out_of_range`       | `rating` was outside 0.5–5 or not a half-step. |
| `rating_not_numeric`        | `rating` was non-numeric. |
| `author_entry_malformed`    | An entry in `authors` lacked a `\|` or had both halves blank. |
| `internal_error`            | An unexpected exception fired inside the row's transaction. The exception is logged via `Log::error`; the response carries a generic message. |

The whole-file `header_invalid` code is returned at 422 and is not present in the per-row `results` array.

## Dry run

`POST /api/bulk-upload` with `dry_run=1` (or `true`) runs every row through the same code path, but rolls back each row's transaction instead of committing. The response shape is identical to a real run with `"dry_run": true` echoed back.

One subtlety: find-or-create inside a dry-run row sees rows created by *earlier* dry-run rows only within that row's transaction (which is then rolled back). A dry-run of a CSV that would create the same author across two rows reports two creates on the second row's lookup-then-create path inside its own transaction. The summary counts in dry-run can therefore be slightly off for cross-row dedupe, but per-row failures (the thing dry-run exists to catch) are accurate.

The header-invalid 422 path is unaffected by `dry_run`.

## Non-obvious decisions and gotchas

- **Per-row transactions, not whole-file.** Each row runs its own `DB::beginTransaction` / `DB::commit`; a failing row rolls back its own writes only and the loop continues. There is no all-or-nothing mode — partial imports are the design. Callers must inspect `summary` and `results` to decide what to do.
- **`fclose($handle)` is in a `finally`.** A truly unexpected exception escaping the per-row catch will not leak the file handle.
- **Format lookup is case-insensitive via `whereRaw('LOWER(name) = ?', …)`.** Non-index-friendly at scale, but the `formats` table has fewer than a dozen rows in practice. Bulk upload does not create new formats — unknown format names fail the row.
- **`Audiobook` is matched case-insensitively (`strcasecmp`)** when deciding whether `audio_runtime` or `page_count` is required. The `formats.name` value still has to be exactly `Audiobook` for the rest of the app (the SPA's hardcoded `format_id === 2` checks and `BookController::index`'s name comparison both expect that exact string — see `/feature-plans/reset-database.md`).
- **`page_count` defaults to `0` for audiobook rows with a blank `page_count`.** The `versions.page_count` column is `NOT NULL` in the schema. Storing `0` is unambiguous as "n/a for an audiobook"; if a stricter representation is wanted in future, that's a schema change.
- **Author slug derivation is `Str::slug(trim($first.' '.$last))`.** Same rule as `AuthorFactory`. This is one rule rather than the four divergent normalizers documented before; the broader consolidation across the rest of the codebase is tracked in `/feature-plans/authors.md`.
- **Genre case dedupe stores the trimmed input as-typed.** The lookup is case- and whitespace-insensitive, but if a genre is being created for the first time the value persisted is whatever the row supplied (after `trim`).
- **Version dedupe key is `(book_id, format_id, version_nickname)`.** Two paperback rows with different `version_nickname` values produce two versions; two paperback rows with the same blank nickname produce one shared version.
- **Read instances are *always* created when `date_read` is set.** There is no dedupe on `(version_id, user_id, date_read)` — a CSV with two identical rows including the same `date_read` will create two `ReadInstance`s. This is intentional: the importer cannot tell whether the duplicate is an actual re-read recorded twice or a CSV mistake. Audit your CSV before importing if duplicates would be a problem.
- **`internal_error` does not leak exception messages.** The catch block logs the exception via `Log::error` with row context, then returns a generic message in the response. SQL driver text and Carbon parse errors do not appear in the JSON.
- **No file size or row count limit at the app layer.** Inherits PHP's `upload_max_filesize` / `post_max_size`. Tracked as a future improvement.
- **No async / job queue.** The request runs synchronously and blocks until the whole file is processed. Tracked as a future improvement.

## Related

- Plan file: `/feature-plans/bulk-upload.md` — remaining limitations and future improvements (auth/role, rate limit, async, frontend template/preview/undo, performance batching).
- `/feature-plans/bulk-upload-hardening.md` — the plan this rewrite executed.
- `/feature-plans/reset-database.md` — uses this importer as the primary restore path.
- `/documentation/books.md` — `Book` / `Version` / `ReadInstance` schema, custom PKs, rating mutator, slug rules.
- `/documentation/new-book-creation.md` — the interactive creation flow this surface bypasses entirely.
- `/documentation/authors.md`, `/documentation/genres.md`, `/documentation/formats.md` — taxonomy attached / matched per row.
