---
path: /feature-plans/
status: living
---

# Bulk upload

Tracks rough edges and follow-up work for the CSV bulk-import surface. Descriptive content lives in `/documentation/bulk-upload.md`.

This is the lowest-traffic creation path in the app — fast to break, easy to ignore. Items below assume bulk upload remains a power-user / data-migration tool rather than a core daily flow.

## Known limitations

### Authorization & ownership

- **No per-user ownership of bulk-uploaded books.** Same multi-tenant gap as the rest of the book domain (see `/feature-plans/books.md`). Read instances are user-scoped via `auth()->id()` at insert time, but the books and authors are global.
- **No role gate.** Any authenticated user can bulk-upload. There's no admin-only restriction even though the surface is the closest thing the app has to a destructive batch operation. (It's not actually destructive — only inserts — but it can dramatically reshape the catalog.)
- **No rate limit.** A user can repeatedly POST large CSVs and saturate DB / PHP-FPM. Default Laravel throttling does not apply to this route.

### Validation & request shape

- **No `FormRequest` for the file or its contents.** `$request->validate(['csv_file' => 'required|file|mimes:csv,txt'])` is the only structured check; everything per-row is hand-rolled inline.
- **No CSV header validation.** The first line is consumed and discarded — a CSV with the wrong column order is happily accepted and writes the wrong fields. Detecting "header looks like `[Title, Author_FNAME, …]`" before processing would catch the most common user mistake.
- **No file size or row count limit at the app layer.** Inherits PHP's `upload_max_filesize` / `post_max_size`. A pathological 100MB CSV will run to completion or hit `max_execution_time` mid-file.
- **`Rating` accepts anything.** No range check, no numeric type check. `(float) 'abc'` is `0`; `7` becomes `14` after the doubling mutator. Out-of-range values silently store nonsense ratings.
- **`Date_Read` is hardcoded to `n/j/Y`.** No fallback to `Y-m-d` or `m/d/Y`-with-leading-zeros. The error surfaces as a Carbon exception message — not user-friendly.
- **`Format` does not auto-create.** Unknown format names fail the row instead of creating a new format. This is probably correct (formats are a controlled vocabulary) but should be deliberate, not incidental — and the error message should hint at the valid set rather than just `"<X>" not found`.

### Data integrity

- **Slug collision check is racy.** `Book::where('slug', $slug)->exists()` is read-then-write, with no DB-level unique constraint on `books.slug` to backstop. Concurrent uploads of the same title would both pass the check and both `Book::create`. Tracked alongside the broader slug-uniqueness work in `/feature-plans/books.md`.
- **Author slug normalizer is the fourth divergent rule.** `BulkUploadController` strips non-alphanumerics, no length cap, *does not collapse internal whitespace* (`'A   B'` → `a-b` here, but `a-b` after collapse elsewhere). See `/feature-plans/authors.md` for the consolidation work; bulk upload must be in the migration scope.
- **`Author::firstOrCreate` matches on slug only.** A typo'd CSV that produces the same slug as an existing author silently attaches without flagging the name mismatch. There's no "matched existing — names differ" warning in the row result.
- **One author per row, no multi-author column.** The CSV format has no representation for co-authors. A two-author book imported via bulk upload silently loses the second author until the user re-edits the book by hand.
- **One version per row, slug collision blocks the second.** Importing the hardcover and audiobook of the same title requires two rows; the second is `skipped` because the slug already exists. The user has no way to say "this is a *new version* of an existing book" via CSV.
- **`audio_runtime` and `nickname` are unrepresented.** Audiobook rows persist with `audio_runtime = null`. Nicknames cannot be set at import time.
- **`Genre::firstOrCreate(['name' => …])` doesn't normalize.** `'Sci-Fi'` and `'sci-fi'` and `'sci fi'` create three separate genres. Same issue as elsewhere in the app — the normalization needs to land on `Genre`, not on each call site.
- **Genre column requires CSV quoting for multi-genre cells.** A user editing the CSV in a basic text editor and writing `sci-fi, dystopia` without surrounding quotes will see the second genre become column 8 — pushing every later column right and likely failing later rows. The error UX gives no hint that this is what happened.
- **Read-instance `book_id` and `version_id` consistency is correct here, but unenforced at the DB level.** `BulkUploadController` always pairs them correctly. Anywhere else that creates `ReadInstance` doesn't have to. Tracked in `/feature-plans/read-history.md`.

### Error handling

- **All responses are HTTP 200, even when every row fails.** Callers must inspect `summary.failed` / `summary.succeeded`. A misconfigured client checking only HTTP status will treat a 100% failure as success.
- **Raw exception messages leak to the response.** Catch block returns `$e->getMessage()` directly — SQL driver text, Carbon parse errors, etc. In production this is information leakage; in development it's the only debug aid because the loop swallows the exception.
- **No transaction across the file.** A row that succeeds is committed immediately; a later cataclysmic failure (DB connection drop mid-file) leaves a partially imported file with no obvious rollback path. Whether this is desirable depends on the use case — but it's currently undocumented.
- **The request blocks the user for the full duration.** No progress streaming, no async. A 1000-row file with slow DB writes can run for minutes; the user sees only the spinner.
- **`fclose($handle)` is not in a `finally`.** A truly unexpected exception (outside a row's catch) escapes without closing the file handle. Practically PHP cleans up on script end, but it's worth tightening.

### Performance & query shape

- **Format lookup uses `whereRaw('LOWER(name) = ?', …)`.** Non-index-friendly. Trivial cost today (formats has < 10 rows); cheap to fix by storing a normalized slug column.
- **`firstOrCreate` calls run individually, no batching.** Each row issues separate queries for the slug existence check, the author lookup/create, every genre lookup/create, the version create, and the optional read instance create. A 500-row file is at minimum ~3000 round trips. A staged approach (parse all, dedupe in PHP, batch insert) would be dramatically faster.
- **No prepared-statement reuse across rows.** Eloquent recompiles each query. Not the bottleneck today but worth knowing.

### Frontend & UX

- **No CSV template / example download.** Users have to read the docs (or this file) to know the column order. A "Download empty template" button on `BulkUploadView` would prevent the most common mistake.
- **No pre-upload preview.** The UI accepts a file and immediately POSTs on submit. There's no "we parsed N rows, here's a preview of the first 5" intermediate step.
- **Per-row results table is unbounded.** A 5000-row response renders 5000 `<tr>` elements at once. The table has `max-h-96` overflow but the DOM is still all there.
- **No filter / sort on the results table.** A user looking only at the failed rows has to scroll past every success.
- **`error` (top-level) and `results` (per-row) are separate paths.** Network failures show the top-level `error`; per-row failures only appear in the table. A user who saw "succeeded: 0, failed: 200" might not realize that's a different state from "network failed and nothing happened".
- **No retry / resume.** A file with 10 rows that fail because of a typo'd format requires the user to re-upload the entire corrected file. Successful rows from the previous run are then `skipped` (good), but the failures are not preserved separately for review.
- **No way to undo an upload.** A bulk upload that goes wrong (wrong file, double-uploaded) has no "delete the books I just imported" affordance. The user has to manually delete each book.

### Extensibility

- **No tests.** No coverage for happy-path import, malformed header, missing required fields, format mismatch, slug collision, Carbon date parse failure, multi-genre quoted vs unquoted, or the rating-doubling-via-mutator behavior.
- **Logic is monolithic in `BulkUploadController::upload`.** Slug rules, author/genre `firstOrCreate`, format lookup, transaction management, and result aggregation are all inline. Extracting a `BulkImportService` (and reusing the same slug helpers as the rest of the book domain — see `/feature-plans/books.md` and `/feature-plans/authors.md`) would make the handler readable and the bits testable in isolation.
- **The CSV format is a contract with no versioning.** If a column is added or moved, every existing user's saved CSVs break silently. A `version` column or a header-validation step would let the importer reject mismatched files explicitly.

## Future improvements

In rough priority order.

1. **Add Feature tests** for happy path, header order mismatch, each per-row failure mode (missing required, missing author, unknown format, slug collision, malformed date, malformed rating), the multi-genre quoted/unquoted cases, and the rating-doubling. Necessary before any of the structural cleanup below.
2. **Validate the CSV header.** Refuse files whose header doesn't match the expected `[Title, Author_FNAME, Author_LNAME, Version_Format, Version_PageCount, Date_Read, Rating, Genres]` (case-insensitive). Returning a clear "your column order looks wrong" error prevents the worst foot-gun.
3. **Extract a `BulkImportService`.** Move parsing, per-row validation, and the persistence calls out of the controller. Reuse the consolidated slug helper from `/feature-plans/books.md` / `/feature-plans/authors.md` rather than carrying a fourth slug normalizer.
4. **Stop returning HTTP 200 on whole-file failure.** Set 422 when `failed > 0 && succeeded === 0`; keep 200 for partial success. Strip `getMessage()` exposure and replace with structured error codes (`format_not_found`, `slug_collision`, `date_parse_failed`, `internal_error`).
5. **Introduce a CSV template download.** A `GET /api/bulk-upload/template` returning an empty-but-correct CSV with the header. Wire a "Download template" button onto `BulkUploadView`.
6. **Pre-upload preview.** Parse client-side (or server-side with a `dry_run=1` flag), show the first N rows + a count, then prompt to confirm. Catches column-order mistakes before any writes.
7. **Add file size and row count limits.** App-layer caps independent of PHP config; reject files over (say) 5MB or 5000 rows with a clear error.
8. **Add a multi-genre comma escape that doesn't require CSV quoting.** Either accept `;`-separated genres or document the quoting requirement loudly in the template / error messages. Pick one.
9. **Support multi-version per book in the CSV.** Either a separate `versions.csv` upload referenced by book slug, or a row-grouping convention (`Title` blank means "additional version of the previous row's book"). Removes the silent "second version skipped" trap.
10. **Support multi-author per book.** Either repeat the author columns, or accept a `Authors` column with a delimited list. Today bulk upload silently drops co-authors.
11. **Date parsing accepts multiple formats.** Try `Y-m-d`, `n/j/Y`, `m/d/Y`, `d/m/Y` (locale-dependent — needs a user setting). Fall through to a clear error if none match.
12. **Rating range validation.** Require `0.5 <= rating <= 5` in 0.5 steps. Reject the row otherwise rather than silently doubling garbage.
13. **Async processing for large files.** Move the loop into a queued job; return a job id immediately and let the SPA poll a `GET /bulk-upload/jobs/{id}` for progress and results. Required if the row count cap goes above a couple hundred.
14. **Undo / dry-run.** A `dry_run` mode that returns the same `results` shape without writing. An "undo last upload" affordance keyed off a per-upload tag (e.g. a `bulk_upload_id` column on `books`) for the 5-minute "I uploaded the wrong file" recovery window.
15. **Filter / sort the results table.** Tabs or filters for `success` / `skipped` / `failed`. Default to showing failures first when any exist.
16. **Rate limit the route** (e.g. one bulk upload in flight per user, plus a per-day cap).
17. **Coordinate normalization with the rest of the domain.** When the global slug helper lands (`/feature-plans/authors.md` item 2), bulk upload must be in the cutover. Same for genre name normalization (`/feature-plans/genres.md`).
