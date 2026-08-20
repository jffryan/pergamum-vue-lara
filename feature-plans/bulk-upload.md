---
path: /feature-plans/
status: living
---

# Bulk upload & export

Tracks rough edges and follow-up work for the CSV bulk-import surface and its
inverse, `GET /api/export`. Descriptive content lives in
`/documentation/bulk-upload.md`; the reset procedure the pair exists to make
safe is `/documentation/database-reset.md`.

This file absorbed what was left of the old `/feature-plans/reset-database.md`
when the export closed it out. That plan is deleted: the runbook is now
documentation, and the CSV contract is the only thing a reset depends on.

This is the lowest-traffic creation path in the app — fast to break, easy to ignore. Items below assume bulk upload remains a power-user / data-migration tool rather than a core daily flow.

Everything previously designed here has shipped — the CSV contract and per-row failure model, the cutover to the shared slug and rating helpers, the exception seam, and importing into a new list. See `/documentation/bulk-upload.md` for the resulting contract and `CHANGELOG.md` for the entries. What remains below is the operational and UX surface around the importer.

## Known limitations

### Authorization & ownership

- **Books and authors from an import are global; read instances are not.** Rows land in the shared catalog while `read_instances.user_id` comes from the session — which is the intended split (see `/documentation/books.md`), but worth knowing before one account imports a 500-row backlog into the other's library view.
- **No role gate.** Any authenticated user can bulk-upload. There's no admin-only restriction even though the surface is the closest thing the app has to a destructive batch operation. (It's not actually destructive — only inserts and find-or-create — but it can dramatically reshape the catalog.)
- **No rate limit.** A user can repeatedly POST large CSVs and saturate DB / PHP-FPM. Default Laravel throttling does not apply to this route.

### Validation & request shape

- **No `FormRequest` for the CSV body.** The inline `$request->validate([...])` in `BulkUploadController` covering `csv_file`, `dry_run`, and `list_name` is the only structured request-level check; per-row validation lives in `BulkImportService::validateRow`.
- **No file size or row count limit at the app layer.** Inherits PHP's `upload_max_filesize` / `post_max_size`. A pathological 100MB CSV will run to completion or hit `max_execution_time` mid-file.
- **Bulk upload does not auto-create formats.** Unknown format names fail the row with `format_not_found`. This is probably correct (formats are a controlled vocabulary) but the error message could hint at the valid set rather than just `"<X>" not found`.
- **Read instances are not deduped against existing read history.** Two CSV rows with the same `(title, format, version_nickname, date_read)` produce two `ReadInstance` rows. By design — the importer can't tell a duplicate from an actual repeat — but worth noting.

### Data integrity

- **`books.slug` and `authors.slug` are uniquely indexed at the DB level,** so `BulkImportService::resolveBook` / `resolveAuthors` cannot silently double-insert on a concurrent upload — a losing race produces a `QueryException`. The service still does the find-by-slug check first, but the constraint is the actual guarantee. If a future bulk path runs without an outer transaction, it should catch the violation and re-fetch.
- **`Author` find-or-create matches on slug only.** A typo'd CSV that produces the same slug as an existing author silently attaches without flagging the name mismatch. There's no "matched existing — names differ" warning in the row result.
- **Audiobook detection is hardcoded.** `BulkImportService::validateRow` decides which of `page_count` / `audio_runtime` a row requires with `strcasecmp($format->name, 'Audiobook')`, and defaults `page_count` to `0` for audiobooks. That is the third copy of the same test — `BookController::prepareVersions` has an `if/elseif` chain on format name and the SPA checks `format_id === 2`. `/feature-plans/books.md` proposes a `FormatHandler` registry keyed by format slug; its two questions ("which field is required", "what to default") are exactly these two branches. **Do not invent a bulk-local abstraction in the meantime** — leave the `strcasecmp` and name bulk upload in the `FormatHandler` cutover when it lands.
- ~~**Author resolution is duplicated three ways.**~~ Fixed. Bulk's semantics were declared canonical and moved into `AuthorService::attachToBook` — find-or-create by slug, dedupe against already-attached authors, continue `author_ordinal` from the book's current max. `BulkImportService::attachAuthors` now delegates, as do both book controllers. `parseAuthors` still owns the CSV's own concern (splitting `First|Last` and rejecting an entry with neither half), and its accept/reject rule is now shared with the form doors via `ValidatesAuthorNames`. Pinned by `tests/Feature/Authors/AuthorIngestTest`.
- **Genre normalization is local.** `BulkImportService::attachGenres` does a case- and whitespace-insensitive lookup, then creates with the trimmed input as-typed. The same normalization should land on `Genre` itself so every call site is consistent (`/feature-plans/genres.md`).
- **Read-instance `book_id` and `version_id` consistency is correct here, but unenforced at the DB level.** `BulkImportService` always pairs them correctly. Anywhere else that creates `ReadInstance` doesn't have to. Tracked in `/feature-plans/read-history.md`.
- **Version match leaves existing fields alone on re-import.** `(book_id, format_id, version_nickname)` is the dedupe key; on a match, `page_count` and `audio_runtime` are not overwritten. This protects hand edits but means a CSV that genuinely fixes a wrong page count is a no-op.

### Error handling

- **The request blocks the user for the full duration.** No progress streaming, no async. A 1000-row file with slow DB writes can run for minutes; the user sees only the spinner.

### Performance & query shape

- **Format lookup uses `whereRaw('LOWER(name) = ?', …)`.** Non-index-friendly. Trivial cost today (formats has < 10 rows); cheap to fix by storing a normalized slug column.
- **Find-or-create runs row-by-row, no batching.** Each row issues separate queries for the book lookup, every author lookup/create, every genre lookup/create, the version lookup/create, and the optional read instance create. A 500-row file is at minimum a few thousand round trips. A staged approach (parse all, dedupe in PHP, batch insert) would be dramatically faster.
- **No prepared-statement reuse across rows.** Eloquent recompiles each query. Not the bottleneck today but worth knowing.

### Import into a new list

- **No merge into an existing list** — deliberate; merging would need reconciliation semantics the importer has no basis to resolve. See `/documentation/bulk-upload.md`.
- **All-or-nothing per upload.** There is no per-row column to exclude a row from the list.
- **No warning when an imported version is already on some *other* list.**
- **Failed rows are silently absent from the list.** The user reconciles against the results table.
- **A name collision rejects the whole upload after the file has been sent** but before any work. Cheap in server terms, but the user re-uploads the file.
- **`list_name` and the per-row `lists` column are two mechanisms for one idea.** `list_name` creates one brand-new list and rejects a taken name; `lists` find-or-creates per row and treats an existing list as the target. Both are right for what they do — restore wants idempotence, "file this import together" wants a fresh list — but a reader meets two answers to "how do I get an import onto a list", and `ListCollector` exists only to serve the first. Worth collapsing if a third list-writing path ever appears.

### Export

- **The export is a full dump with no filters.** No date range, no "just this list", no per-scope export. Fine as a backup; not a sharing or reporting surface.
- **Users don't roundtrip and won't.** Deliberate — the alternative is password hashes in a file people email around. Documented in `/documentation/database-reset.md`.
- **A re-import onto a populated database is a merge, not a replace.** Every layer is find-or-create and matched versions keep their existing field values, so an export cannot be used to push corrections back in.
- **The export is not rate limited or role gated**, same as the import. It is the cheapest way for an authenticated user to pull the whole catalog in one request.
- **`GET /api/export` streams but still runs one query per book's relations.** `cursor()` keeps memory flat; the query count is proportional to the catalog. Nothing batches.

### Frontend & UX

- **No CSV template / example download.** Users have to read the docs to know the column names. A "Download empty template" button on `BulkUploadView` would prevent the most common mistake.
- **No pre-upload preview.** The UI accepts a file and immediately POSTs on submit. The `dry_run` flag exists at the API layer but isn't surfaced as a "preview before committing" step in the SPA.
- **Per-row results table is unbounded.** A 5000-row response renders 5000 `<tr>` elements at once. The table has `max-h-96` overflow but the DOM is still all there.
- **No filter / sort on the results table.** A user looking only at the failed rows has to scroll past every success.
- **No retry / resume.** A file with 10 rows that fail because of a typo'd format requires the user to re-upload the entire corrected file. Successful rows from the previous run are then no-ops (good), but the failures are not preserved separately for review.
- **No way to undo an upload.** A bulk upload that goes wrong (wrong file, double-uploaded) has no "delete the books I just imported" affordance. The user has to manually delete each book. Importing into a new list softens this — the list records what one upload brought in — but does not undo anything.

## Future improvements

In rough priority order.

1. **Surface `dry_run` in the SPA.** A "Preview" button on `BulkUploadView` that posts with `dry_run=1` and shows the same per-row results table without writes. Frees the user from having to trust the API alone. The API layer already accepts `dryRun`; this is the view work. The preview request must carry `list_name` too, or it validates a different request than the one submitted — build the button so both paths read the same view state.
2. **CSV template download.** A `GET /api/bulk-upload/template` returning an empty-but-correct CSV with the new header. Wire a "Download template" button onto `BulkUploadView`.
3. **Add file size and row count limits.** App-layer caps independent of PHP config; reject files over (say) 5MB or 5000 rows with a clear error.
4. **Async processing for large files.** Move the loop into a queued job; return a job id immediately and let the SPA poll a `GET /bulk-upload/jobs/{id}` for progress and results. Required if the row count cap goes above a couple hundred.
5. **Filter / sort the results table.** Tabs or filters for `success` / `failed`. Default to showing failures first when any exist.
6. **Undo affordance.** A per-upload tag (e.g. a `bulk_upload_id` column on `books`) with a 5-minute "I uploaded the wrong file" delete affordance. Note importing into a new list already provides *most* of what this wanted — a durable record of what one upload brought in. If `bulk_upload_id` lands, treat the two as views of the same batch rather than building a second grouping mechanism.
7. **Rate limit the route** (e.g. one bulk upload in flight per user, plus a per-day cap).
8. **Coordinate normalization with the rest of the domain.** Genres are done — `BulkImportService::attachGenres` calls `GenreService::attachByName`, so an import and a book form now produce the same rows. The `FormatHandler` registry (`/feature-plans/books.md`) is still pending, and bulk upload must be named in that cutover. Author name handling is the remaining unconsolidated case.
9. **Finer-grained idempotency reporting.** A re-import currently reports each row as `succeeded` even when zero new rows were written. A `noop` status — or a per-row breakdown of "books / versions / read instances created" — would let a caller verify a re-import didn't silently no-op.
10. **Add filters to the export.** `?list=`, `?year=`, `?discarded=` — the same axes the library already browses by. The full dump is the backup case and is done; a filtered export is the sharing case and isn't.
11. **Make summary totals balance.** Today blank rows inflate `summary.total` but are not counted as `succeeded`, `failed`, or `skipped`, so `succeeded + failed + skipped != total` for any file with blank rows. Either count blank rows as `skipped` (preferred — matches the field name) or exclude them from `total`. Pinned by `tests/Feature/BulkUpload/BulkUploadTest.php::test_blank_rows_inflate_total_but_are_not_counted_as_skipped`.
