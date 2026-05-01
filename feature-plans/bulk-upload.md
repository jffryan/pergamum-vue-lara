---
path: /feature-plans/
status: living
---

# Bulk upload

Tracks rough edges and follow-up work for the CSV bulk-import surface. Descriptive content lives in `/documentation/bulk-upload.md`.

This is the lowest-traffic creation path in the app — fast to break, easy to ignore. Items below assume bulk upload remains a power-user / data-migration tool rather than a core daily flow.

The data-fidelity, header-validation, error-legibility, and service-extraction items previously listed here were resolved by the bulk-upload hardening shipped 2026-04-29 (see `/documentation/bulk-upload.md` for the resulting contract and `CHANGELOG.md` for the entry). What remains is the operational/UX surface around the importer.

## Known limitations

### Authorization & ownership

- **No per-user ownership of bulk-uploaded books.** Same multi-tenant gap as the rest of the book domain (see `/feature-plans/books.md`). Read instances are user-scoped via `auth()->id()` at insert time, but the books and authors are global.
- **No role gate.** Any authenticated user can bulk-upload. There's no admin-only restriction even though the surface is the closest thing the app has to a destructive batch operation. (It's not actually destructive — only inserts and find-or-create — but it can dramatically reshape the catalog.)
- **No rate limit.** A user can repeatedly POST large CSVs and saturate DB / PHP-FPM. Default Laravel throttling does not apply to this route.

### Validation & request shape

- **No `FormRequest` for the CSV body.** `$request->validate(['csv_file' => 'required|file|mimes:csv,txt', 'dry_run' => 'sometimes|boolean'])` is the only structured request-level check; per-row validation lives in `BulkImportService`.
- **No file size or row count limit at the app layer.** Inherits PHP's `upload_max_filesize` / `post_max_size`. A pathological 100MB CSV will run to completion or hit `max_execution_time` mid-file.
- **Bulk upload does not auto-create formats.** Unknown format names fail the row with `format_not_found`. This is probably correct (formats are a controlled vocabulary) but the error message could hint at the valid set rather than just `"<X>" not found`.
- **Read instances are not deduped against existing read history.** Two CSV rows with the same `(title, format, version_nickname, date_read)` produce two `ReadInstance` rows. By design — the importer can't tell a duplicate from an actual repeat — but worth noting.

### Data integrity

- **`books.slug` and `authors.slug` are uniquely indexed at the DB level,** so `BulkImportService::resolveBook` / `resolveAuthors` cannot silently double-insert on a concurrent upload — a losing race produces a `QueryException`. The service still does the find-by-slug check first, but the constraint is the actual guarantee. If a future bulk path runs without an outer transaction, it should catch the violation and re-fetch.
- **Author slug derivation is local.** `BulkImportService` uses `Str::slug(trim($first.' '.$last))` directly. The codebase still has divergent author-slug rules elsewhere (`BookController::handleAuthors`, `NewBookController::handleAuthors`, `AuthorController::getOrSetToBeCreatedAuthorsByName`). When the consolidated helper from `/feature-plans/authors.md` lands, bulk upload should call into it.
- **`Author` find-or-create matches on slug only.** A typo'd CSV that produces the same slug as an existing author silently attaches without flagging the name mismatch. There's no "matched existing — names differ" warning in the row result.
- **Genre normalization is local.** `BulkImportService::attachGenres` does a case- and whitespace-insensitive lookup, then creates with the trimmed input as-typed. The same normalization should land on `Genre` itself so every call site is consistent (`/feature-plans/genres.md`).
- **Read-instance `book_id` and `version_id` consistency is correct here, but unenforced at the DB level.** `BulkImportService` always pairs them correctly. Anywhere else that creates `ReadInstance` doesn't have to. Tracked in `/feature-plans/read-history.md`.
- **Version match leaves existing fields alone on re-import.** `(book_id, format_id, version_nickname)` is the dedupe key; on a match, `page_count` and `audio_runtime` are not overwritten. This protects hand edits but means a CSV that genuinely fixes a wrong page count is a no-op.

### Error handling

- **The request blocks the user for the full duration.** No progress streaming, no async. A 1000-row file with slow DB writes can run for minutes; the user sees only the spinner.

### Performance & query shape

- **Format lookup uses `whereRaw('LOWER(name) = ?', …)`.** Non-index-friendly. Trivial cost today (formats has < 10 rows); cheap to fix by storing a normalized slug column.
- **Find-or-create runs row-by-row, no batching.** Each row issues separate queries for the book lookup, every author lookup/create, every genre lookup/create, the version lookup/create, and the optional read instance create. A 500-row file is at minimum a few thousand round trips. A staged approach (parse all, dedupe in PHP, batch insert) would be dramatically faster.
- **No prepared-statement reuse across rows.** Eloquent recompiles each query. Not the bottleneck today but worth knowing.

### Frontend & UX

- **No CSV template / example download.** Users have to read the docs to know the column names. A "Download empty template" button on `BulkUploadView` would prevent the most common mistake.
- **No pre-upload preview.** The UI accepts a file and immediately POSTs on submit. The `dry_run` flag exists at the API layer but isn't surfaced as a "preview before committing" step in the SPA.
- **Per-row results table is unbounded.** A 5000-row response renders 5000 `<tr>` elements at once. The table has `max-h-96` overflow but the DOM is still all there.
- **No filter / sort on the results table.** A user looking only at the failed rows has to scroll past every success.
- **No retry / resume.** A file with 10 rows that fail because of a typo'd format requires the user to re-upload the entire corrected file. Successful rows from the previous run are then no-ops (good), but the failures are not preserved separately for review.
- **No way to undo an upload.** A bulk upload that goes wrong (wrong file, double-uploaded) has no "delete the books I just imported" affordance. The user has to manually delete each book.

## Future improvements

In rough priority order.

1. **Surface `dry_run` in the SPA.** A "Preview" button on `BulkUploadView` that posts with `dry_run=1` and shows the same per-row results table without writes. Frees the user from having to trust the API alone.
2. **CSV template download.** A `GET /api/bulk-upload/template` returning an empty-but-correct CSV with the new header. Wire a "Download template" button onto `BulkUploadView`.
3. **Add file size and row count limits.** App-layer caps independent of PHP config; reject files over (say) 5MB or 5000 rows with a clear error.
4. **Async processing for large files.** Move the loop into a queued job; return a job id immediately and let the SPA poll a `GET /bulk-upload/jobs/{id}` for progress and results. Required if the row count cap goes above a couple hundred.
5. **Filter / sort the results table.** Tabs or filters for `success` / `failed`. Default to showing failures first when any exist.
6. **Undo affordance.** A per-upload tag (e.g. a `bulk_upload_id` column on `books`) with a 5-minute "I uploaded the wrong file" delete affordance.
7. **Rate limit the route** (e.g. one bulk upload in flight per user, plus a per-day cap).
8. **Coordinate normalization with the rest of the domain.** When the global slug helper lands (`/feature-plans/authors.md` item 2), bulk upload must be in the cutover. Same for genre name normalization (`/feature-plans/genres.md`).
9. **Finer-grained idempotency reporting.** A re-import currently reports each row as `succeeded` even when zero new rows were written. A `noop` status — or a per-row breakdown of "books / versions / read instances created" — would let a caller verify a re-import didn't silently no-op.
10. **Build a real export endpoint that produces a CSV the importer can roundtrip without loss.** Removes the data-loss surface from any future reset (lists are still not in the importer's contract — see `/feature-plans/reset-database.md`).
11. **Make summary totals balance.** Today blank rows inflate `summary.total` but are not counted as `succeeded`, `failed`, or `skipped`, so `succeeded + failed + skipped != total` for any file with blank rows. Either count blank rows as `skipped` (preferred — matches the field name) or exclude them from `total`. Pinned by `tests/Feature/BulkUpload/BulkUploadTest.php::test_blank_rows_inflate_total_but_are_not_counted_as_skipped`.
