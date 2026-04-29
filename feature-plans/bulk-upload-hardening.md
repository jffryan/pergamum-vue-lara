---
path: /feature-plans/
status: shipped
---

# Bulk-upload hardening

> Shipped 2026-04-29. Service, header-validated CSV contract, structured error codes, multi-author/version/read fidelity, multi-format date parsing, rating range validation, audio-runtime requirement, slug-truncation fix, and `dry_run` flag are all in place. Tests live in `tests/Feature/BulkUpload/BulkUploadTest.php`. The remaining future-improvement items (auth/role gate, rate limit, async, frontend template/preview/undo, performance batching, finer idempotency reporting) stay in `/feature-plans/bulk-upload.md`. The `books.slug` unique index from `/feature-plans/books.md` still has to land before the actual restore.

## Goal

Harden `BulkUploadController` and the CSV contract so the bulk importer is reliable enough to use as the primary path for restoring chunks of the database. The driver is `/feature-plans/reset-database.md`: the dev DB is wiped and we need to re-import without the silent data loss documented in `/feature-plans/bulk-upload.md` (multi-read collapse, multi-version collapse, dropped co-authors, dropped audio runtime, brittle date parsing, opaque error reasons).

We are explicitly rewriting the CSV contract — there are no existing exports to roundtrip, so we can pick the columns and encoding that produce the cleanest importer.

In scope: data fidelity, error legibility, header validation, service extraction, tests. Out of scope: auth/role gates, rate limits, async processing, frontend template/preview/undo, multi-tenant ownership, performance batching. These remain in `/feature-plans/bulk-upload.md`.

## CSV contract (new)

**Header is required and validated.** Column order is no longer positional — the importer reads by header name. Header comparison is case-insensitive and trims whitespace. Unknown columns are rejected (strict mode) so a misnamed column doesn't silently become a no-op.

| Column            | Required | Notes |
|-------------------|----------|-------|
| `title`           | yes      | Used to derive `Book.slug` (`Str::slug`, no truncation suffix). |
| `authors`         | yes      | `;`-separated list of entries; each entry is `First\|Last`. Single-author rows use one entry. Empty `First` or empty `Last` are allowed (one of the two must be non-empty per entry). |
| `format`          | yes      | Looked up case-insensitively in `formats.name`. Must already exist; no auto-create. |
| `page_count`      | yes for non-audio formats | Integer. |
| `audio_runtime`   | yes for `Audiobook` rows, otherwise blank | Integer minutes (simpler than HH:MM; matches the column type today). |
| `version_nickname`| no       | Free text; disambiguates versions sharing `(book, format)` (e.g., two paperbacks). |
| `genres`          | no       | `;`-separated list. Lookup is case-insensitive and trims whitespace; `Fantasy`, `fantasy`, ` Fantasy ` all dedupe. |
| `date_read`       | no       | Accepts `Y-m-d`, `n/j/Y`, `m/d/Y`. Blank means "no read instance for this row." |
| `rating`          | no       | Decimal 0.5–5 in 0.5 steps. Stored as-is; the existing `Rating` mutator does the ×2 on insert. Blank is allowed only when `date_read` is also blank, OR when the user genuinely read it without rating it (then store `null`). |

### Encoding choices and why

- **`;` for list cells, `|` for name parts.** Avoids the CSV-comma-quoting trap (`/feature-plans/bulk-upload.md` Validation §). No cell ever needs to be quoted just because it contains a list.
- **Single `authors` column, not `author_first_name` + `additional_authors`.** Symmetric — the importer doesn't have a "first author is special" branch — and the doubling mutator / co-author drop bug both go away in one place.
- **No `book_nickname` column.** Rarely used, and re-addable via the SPA after import.

### Row semantics

Each row describes one (book, version, optional read instance). Rows are de-duped against existing rows by slug at each layer:

1. **Book**: find-or-create by `slug = Str::slug(title)`. Title on existing books is left alone.
2. **Authors**: each entry → find-or-create by author slug (consolidated rule — see Open Questions). Attach to book if not already attached.
3. **Genres**: each entry → find-or-create by lower-cased trimmed name. Attach to book if not already attached.
4. **Version**: find-or-create by `(book_id, format_id, version_nickname)`. `audio_runtime` and `page_count` are written on create; on existing-version match they're left alone (so re-imports don't overwrite hand-edits).
5. **Read instance**: if `date_read` is non-blank, always create a new `ReadInstance` against the resolved version with `user_id = auth()->id()`. Multiple rows with the same (title, format, nickname) but different dates produce multiple read instances — this is how re-reads roundtrip.

This single-row shape covers every restore scenario:

- One-time read of a paperback: one row.
- Re-read of the same paperback three times: three rows, identical except `date_read` / `rating`.
- Same book in paperback and audiobook: two rows, different `format` (and `audio_runtime` on the audiobook row).
- Owned but unread: one row with blank `date_read` and `rating`.
- Two co-authors: one row, `authors = "Jane|Smith;John|Doe"`.

## Approach

### Backend

1. **Extract `app/Services/BulkImportService.php`.**
   - Public: `importCsv(UploadedFile $file, int $userId): array` returning `['summary' => [...], 'results' => [...]]` matching the current response shape (extended with structured `reason_code`).
   - Internals: `validateHeader(array $header): void` (throws `BulkImportHeaderException`); `processRow(array $row, int $userId): RowResult` (one row, one transaction); private helpers for slug derivation, author resolution, genre resolution, version resolution.
   - Controller becomes ~10 lines: validate file mime, call service, return JSON. On `BulkImportHeaderException`, return 422 with the structured error.

2. **Slug derivation.**
   - Books: `Str::slug($title)`. Drops the buggy `Str::of(...)->limit(50)` chain entirely. No truncation; if the schema needs a cap, add it as a follow-up — `books.slug` is `varchar(255)` today which fits any sane title.
   - Authors: use whatever the consolidated helper is at implementation time. If `/feature-plans/authors.md` item 2 hasn't shipped yet, inline a single rule (`Str::slug(trim($first . ' ' . $last))`) and add a TODO referencing that plan. Do **not** carry a fourth divergent rule.

3. **Structured error reasons.** Each failed-row `result` carries `reason_code` (machine) and `reason` (human). Codes:
   - `header_invalid` (whole-file, 422)
   - `missing_required_field`
   - `format_not_found`
   - `audio_runtime_required`
   - `page_count_required`
   - `date_parse_failed`
   - `rating_out_of_range`
   - `rating_not_numeric`
   - `author_entry_malformed`
   - `internal_error` (raw `$e->getMessage()` is logged via `Log::error` with row context, never returned to the client)

4. **Date parsing helper.** Try `Y-m-d`, `n/j/Y`, `m/d/Y` in order via `Carbon::createFromFormat` with strict mode; first hit wins. Carbon's exception is caught and converted to `date_parse_failed`.

5. **Rating validation.** Reject non-numeric, reject < 0.5 or > 5, reject non-half-step values (`fmod($rating * 2, 1) !== 0.0`).

6. **Per-row transactions stay.** A typo in row 873 must not roll back rows 1–872. `fclose($handle)` moves into a `finally`.

7. **Whole-file response code.** Keep 200 for success and partial success; switch to 422 only when the *header* is invalid (so the SPA's existing per-row table keeps working unchanged for normal runs).

### Frontend

- `BulkUploadView.vue` continues to render `summary` + `results` as today. The per-row template should render `reason` (human string), not `reason_code`. Verify it doesn't crash on the new shape; no UX overhaul in this pass.
- `api/BulkUploadApi.js` unchanged.
- No template-download / preview / undo work — those stay in `/feature-plans/bulk-upload.md` as future improvements.

### Tests

New `tests/Feature/BulkUpload/BulkUploadTest.php` (Feature suite, `RefreshDatabase`, `actingAsUser()`):

Header / file-shape:
- Happy path single row creates book + author + version + read instance.
- Header missing a required column → 422 with `header_invalid`, no rows written.
- Header has an unknown / typo'd column → 422 with `header_invalid` (strict mode).
- Header is case-insensitive and whitespace-tolerant for column *names*.
- Empty file (header only) → 200, summary all zero.
- Truly blank rows in the middle of the file are skipped silently (existing behavior).

Data fidelity:
- Multi-read: two rows, same title + format, different `date_read` → 1 book, 1 version, 2 read instances.
- Multi-version: two rows, same title, different formats → 1 book, 2 versions, and read instances attached to the right version.
- Multi-author: one row with `authors = "A|B;C|D"` → 1 book, 2 authors attached.
- Audiobook row: `format=Audiobook`, `audio_runtime=540` → version persists with runtime; blank `page_count` accepted.
- Genre case dedupe: `Fantasy`, `fantasy`, ` Fantasy ` across rows produce one genre.
- Long title (over 50 chars) gets a clean slug with no `...` suffix.

Per-row failure modes (all return 200 with row marked failed and the right `reason_code`):
- Missing `title` / `format`.
- `format` not in DB.
- `Audiobook` row with blank `audio_runtime`.
- Non-audio row with blank `page_count`.
- `date_read` malformed.
- `rating` non-numeric.
- `rating` out of 0.5–5 range.
- `rating` not a half-step (e.g., 3.7).
- `authors` entry with both halves blank (`"|"` or `";|;"`).

Idempotency:
- Re-uploading the same CSV: existing books/versions/authors/genres are re-used; new read instances are created (because re-reads are valid). The summary should reflect this — `succeeded` increments per row, no `skipped`. (Open question: do we want a separate `idempotent` status for "row produced no new writes"? Default is no; treat as success.)

User scoping:
- Read instances created carry the authenticated user's id. A second user importing the same file gets their own read instances against the shared book/version.

Dry-run:
- A dry-run of a valid file returns the same per-row pass/fail shape as a real run, but DB state is unchanged afterward (no books, authors, versions, genres, or read instances persisted).
- A dry-run of a file with one bad row returns the same per-row failure for that row as a real run would.
- Header-invalid response is independent of `dry_run` (still 422).

## Touches existing systems

- **`app/Http/Controllers/BulkUploadController.php`** — rewritten as a thin handler.
- **New `app/Services/BulkImportService.php`** — owns the logic. First service-shaped piece in this domain.
- **`/feature-plans/reset-database.md`** — Step 1 prep changes: the slug-truncation patch and the multi-read patch are subsumed by this plan. The reset plan should reference this one rather than spec the patches inline. Will edit at the end.
- **`/feature-plans/bulk-upload.md`** — most "Validation," "Data integrity," and "Error handling" bullets are resolved by this plan. Will move resolved items out and rewrite the file's framing once shipped.
- **`/feature-plans/authors.md`** — the consolidated author-slug helper (item 2 there) overlaps. If it hasn't shipped, this plan inlines a single rule and the consolidation work picks it up later; if it ships first, this plan calls into it.
- **`/feature-plans/genres.md`** — same overlap for genre name normalization. Same coordination.
- **`/feature-plans/read-history.md`** — the `(book_id, version_id)` consistency-at-DB-level concern is unchanged here; we keep pairing them correctly but don't add a constraint.
- **`/documentation/bulk-upload.md`** — update after implementation: new CSV contract, new error codes, service extraction.
- **Frontend `BulkUploadView.vue`** — verify the results table still renders; no design changes.

## Resolved decisions

1. **Authors encoding.** `First|Last;First|Last` (semicolon between authors, pipe between name parts). Ugly but unambiguous, no CSV quoting required.
2. **Strict header validation.** Required columns must all be present; unknown columns are rejected with `header_invalid`. No silent "we ignored your typo'd column" mode.
3. **No `noop` status.** Rows that produced zero new writes count as `succeeded`. Response shape stays simple; finer-grained idempotency tracking is logged as a future improvement on `/feature-plans/bulk-upload.md`.
4. **`books.slug` unique index is a prerequisite, not part of this plan.** Tracked in `/feature-plans/books.md` future-improvements item 5. Land it after this hardening lands but before running the actual restore — so the restore is the first import that benefits from the constraint, and any pre-existing duplicates surface in a controlled migration rather than mid-restore.
5. **No restore-mode flag.** Dropped.
6. **Dry-run is in scope.** `?dry_run=1` runs the same row processing through `BulkImportService`, but each row's transaction is rolled back instead of committed. Response shape is identical to a real run — the user gets the full per-row pass/fail breakdown without writes. Useful safety check before a large restore CSV.

### Dry-run mechanics

- Service takes a `bool $dryRun` parameter. The per-row loop's `DB::commit()` becomes `DB::rollBack()` when dry-run is set; the rest of the code path (validation, lookups, find-or-create, error capture) runs unchanged so the response faithfully reflects what a real run would do.
- One subtlety: find-or-create inside a dry-run row sees rows created by *earlier* dry-run rows only within that row's transaction (which we then roll back). So a dry-run of a CSV that creates the same author across two rows will report two creates on the second-row's lookup-then-create path inside its own transaction. This is acceptable — the summary counts will be slightly off for cross-row dedupe in dry-run, but the per-row failures (the thing dry-run actually exists to catch) are accurate. Document this in `/documentation/bulk-upload.md` when it lands.
- The header-invalid 422 path is unaffected by `dry_run`.

## Implementation order

1. Service extraction with current behavior preserved (no semantic changes), plus tests pinning current behavior. This is the safety net.
2. Header validation + new column set. Old positional CSVs are no longer accepted — acceptable because there's no production data to roundtrip.
3. Multi-version + multi-read + multi-author logic, with tests landing alongside.
4. Structured error codes, date-parse fallback, rating validation, audio-runtime requirement, slug-truncation fix.
5. Dry-run flag + tests.
6. Documentation update + cross-plan edits.
7. **(Separate task, before the actual restore runs)** Land the `books.slug` unique index from `/feature-plans/books.md`.

Each step should leave the importer working end-to-end so the reset can run against whatever's landed if priorities shift.
