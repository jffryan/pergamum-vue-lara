---
path: /feature-plans/
status: living
---

# Bulk upload

Tracks rough edges and follow-up work for the CSV bulk-import surface. Descriptive content lives in `/documentation/bulk-upload.md`.

This is the lowest-traffic creation path in the app — fast to break, easy to ignore. Items below assume bulk upload remains a power-user / data-migration tool rather than a core daily flow.

The data-fidelity, header-validation, error-legibility, and service-extraction items previously listed here were resolved by the bulk-upload hardening shipped 2026-04-29 (see `/documentation/bulk-upload.md` for the resulting contract and `CHANGELOG.md` for the entry). What remains is the operational/UX surface around the importer, a consolidation refactor, and one designed-but-unbuilt enhancement (both below).

## Planned: consolidation refactor

**Status:** designed, not implemented. Sequence this **before** the add-to-a-new-list feature — both touch `processRow` and the exception seam, and doing the list feature first means writing new code against rules that are about to change.

### 1. Exception seam (moved here from the list feature)

Generalize before there's a second exception, and give the row path the same treatment as the file path:

- abstract `BulkImportException` (holds `reasonCode`)
- `BulkImportFileException extends BulkImportException` — whole-file, caught by `BulkUploadController` → 422 `{reason_code, reason}`. `BulkImportHeaderException` becomes a subclass; `BulkImportListNameException` (list feature) joins it.
- `BulkImportRowException extends BulkImportException` — row-scoped, caught inside the loop → a failed row in `results`.

The controller catches only the file branch, so a row exception can never escape as a 422. Note `BulkImportHeaderException`'s constructor takes a `$reasonCode` argument that every caller leaves at the default — drop it and hardcode `'header_invalid'` on the subclass.

### 2. Split `processRow` (readability)

`processRow` is 105 lines doing three jobs: unpack nine cells into locals, run seven validation gates with hand-written early returns, then open a transaction and persist. Split it into validation and persistence, using the row exception from item 1 so the gates stay linear:

```php
try {
    $row = $this->validateRow($cells);   // throws BulkImportRowException
} catch (BulkImportRowException $e) {
    return $this->fail($rowNumber, $title, $e->reasonCode, $e->getMessage());
}
```

Be honest about the payoff: total line count barely moves. The win is that validation becomes callable without a file handle or a database — the first thing in this service that can have a `tests/Unit` spec — and the persist block becomes short enough to read in one screen.

The nine values crossing that boundary justify **one** `readonly class ImportRow` with typed properties. One class: no interface, no builder, no hierarchy. A nine-key associative array between two private methods is the thing to avoid, not the thing to reach for.

### 3. Audiobook special-casing — coordinate, do not fix locally

`strcasecmp($format->name, 'Audiobook')` (:152) plus the `page_count = 0` default (:164) is the **third** hardcoded copy of "is this format an audiobook": `BookController::prepareVersions` (:547) has an `if/elseif` chain on format name, and the SPA checks `format_id === 2`. `/feature-plans/books.md` item 6 already proposes a `FormatHandler` registry keyed by format slug.

Bulk upload is the second caller that would justify that registry, and its two branches ("which field is required," "what to default") are exactly the two questions a handler answers. **Do not invent a bulk-local abstraction for it in the meantime** — leave the `strcasecmp` and make sure bulk upload is named in the `FormatHandler` cutover when it lands.

### 4. Author resolution duplication — coordinate, defer

Three implementations: `BulkImportService::attachAuthors`, `BookController::handleAuthors` (:519), `NewBookController::handleAuthors` (:54). Bulk's is the only one that continues `author_ordinal` from the book's current max and dedupes against already-attached authors; the controllers use `firstOrCreate` and let the pivot fall where it may. Consolidating means declaring bulk's semantics canonical and moving them into `AuthorService` (34 lines today — the natural home).

That's a book-pipeline refactor, not a bulk-upload one; `/feature-plans/books.md` and `/feature-plans/authors.md` own it. Bulk upload cannot do it unilaterally without touching both controllers.

### 5. Header contract rigidity (hyperspecific — contract change)

`REQUIRED_HEADERS` includes `page_count` and `audio_runtime`, so a CSV containing nothing but paperbacks is rejected unless it carries an empty `audio_runtime` column. The constant names conflate "this column must be present" with "this value must be non-blank," which is why the doc's column table has to say "yes for non-audio" and "yes for `Audiobook` rows" to paper over it.

Two moves, both worth making:

- Rename to `KNOWN_COLUMNS` / `REQUIRED_COLUMNS` so the constants mean what they say.
- Demote `page_count` and `audio_runtime` to optional *columns* and let the existing per-row `page_count_required` / `audio_runtime_required` gates carry the requirement. Strictly more forgiving — every file valid today stays valid — so this widens the contract rather than breaking it.

### 6. API layer has no `dry_run`

`bulkUpload(file)` in `api/BulkUploadApi.js` never sends `dry_run`, so improvement 1 below cannot be built without changing this signature. Grow it to `bulkUpload(file, { dryRun = false, listName = null } = {})` — an options object rather than positional args, because the list feature adds the second optional immediately. This supersedes the `bulkUpload(file, listName = null)` shape in the list feature's file table below.

### Sequencing

1. Item **1**, then item **2** — seam first, then the split that uses it.
2. Item **6** with whichever of the list feature / improvement 1 lands first.
3. Item **5** on its own, with the doc change (it's a contract widening, so it gets a changelog line).
4. Items **3 + 4** stay deferred to `/feature-plans/books.md` and `/feature-plans/authors.md`.

### Tests

- The 32 existing tests in `BulkUploadTest.php` pass unchanged — this refactor is a no-op on behavior.
- New `tests/Unit`: row validation against a plain array of cells, no file handle, no database — the point of item 2.
- New: a paperback-only CSV with no `audio_runtime` column succeeds (item 5).
- `BulkUploadView.vue` has no frontend spec; pin item 6 in a `BulkUploadApi` spec.

### What this refactor deliberately does not do

- **No repository layer, no interface for `BulkImportService`.** One caller, one implementation.
- **No generic CSV-importer abstraction.** There is one importer and no second on the roadmap. The export endpoint (improvement 10) is a reader with a different shape.
- **No batching / staged-insert rewrite.** That's a performance change that rewrites the loop; keeping it separate keeps this refactor reviewable as a no-op on behavior.
- **No `FormRequest` extraction.** Listed under known limitations, cheap, and orthogonal.

## Planned: add imported versions to a new list

**Status:** designed, not implemented. This is the only non-shipped *feature* in this plan — the section above it is a refactor of the shipped importer, and everything below is limitations/improvements.

### Goal

Let a user file an entire bulk upload into a new list in one step. On `BulkUploadView`, a checkbox (default **off**) reveals a required list-name field; when set, every version the import *found or created* on a successful row is appended to a brand-new list owned by the uploader.

The motivating case is data migration and haul-tracking: you import 40 books from a spreadsheet and immediately want them as a browsable, reorderable collection rather than 40 needles in the global bookshelf. It also softens the "no way to undo an upload" gap (see below) — the list is a durable record of what one upload brought in.

### Scope decisions

- **New list only — never a merge into an existing one.** Merging would drag in reconciliation semantics the importer has no basis to resolve: append vs. dedupe against existing items, where new items land in an existing `ordinal` sequence, and what "already on the list" means when the row matched an existing version. None of that is free, and none of it is what this feature is for. If a user wants the rows in an existing list, they import into a new one and move items with the existing list UI.
- **Versions, not books.** `list_items.version_id` is the FK (see `/documentation/lists.md`); a row resolves to exactly one version, so this is a natural fit. A book imported in two formats across two rows produces two list items.
- **Found *and* created versions both count.** A re-import that creates nothing still files everything into the new list — the list reflects the CSV's contents, not the delta.
- **Successful rows only.** Failed rows contribute nothing. The user cross-references the results table to see what didn't make it.

### Approach

**Request shape.** Add an optional `list_name` field to the existing multipart body. Presence is the opt-in — no separate boolean flag; the UI checkbox simply gates whether the field is sent.

```
POST /api/bulk-upload   (multipart)
  csv_file   file      required
  dry_run    boolean   sometimes
  list_name  string    sometimes|nullable|string|max:255
```

`max:255` and the `Str::slug` derivation match `ListController::store` exactly — the list this creates must be indistinguishable from a hand-created one.

**Pre-flight name check (fails the whole upload).** `lists` is unique on `(user_id, slug)`. Before opening the CSV, the service checks `Str::slug($listName)` against the user's existing lists. On collision: **422, nothing imported.** Rationale: a half-imported CSV attached to nothing is the worst outcome, and "this is a *new* list" has to stay unambiguous. The user renames and re-submits.

This check runs *before* header validation, so a request that is both name-colliding and header-invalid reports `list_name_taken`. Document that ordering — it's arbitrary but callers shouldn't have to guess.

Note the collision is on **slug**, not raw name: `My List` and `my list` collide. Worth a sentence in the doc and an explicit test.

**Exception seam.** Built by refactor item 1 above: `BulkImportListNameException extends BulkImportFileException`, and the controller's existing catch already covers it. If this feature ships first, build that hierarchy here instead of adding a second catch block to `BulkUploadController`.

**Lazy creation — no empty lists.** The list is **not** created up front. It is created inside the *first successful row's* transaction, immediately before that row's `ListItem` insert. Consequences:

- An import where every row fails creates nothing. The user fixes the CSV and retries with the same name — no orphan list to delete first, no spurious collision on retry.
- List creation and the first item insert are atomic; if that row's transaction rolls back, neither exists and the next successful row tries again.
- Later per-row rollbacks don't touch the already-committed list. A partially-successful import yields a partial list, which is consistent with the importer's existing partial-write design.

**Item insertion.** Per successful row, after `resolveVersion`, insert a `ListItem` with `ordinal` = a running counter (so list order is CSV row order), guarded by an **in-memory seen-set of `version_id`**. The dedupe is mandatory, not an optimization: three re-read rows for the same paperback resolve to the same version, and `(list_id, version_id)` is uniquely indexed — without the set, row 2 throws and gets swallowed as `internal_error`, corrupting the results table. Because the list is brand-new, the in-memory set is authoritative; no `SELECT` per row.

**Dry run.** `dry_run=1` creates neither the list nor any items — the per-row rollback already handles it, since both writes live inside row transactions. The **collision check still runs**, so a preview catches a taken name before the real submit. The reported item count comes from the seen-set, which is pure PHP and unaffected by rollback — so unlike the cross-row author/genre dedupe caveat already documented for dry-run, this count is exact.

**Response.** Add a top-level `list` key, `null` when `list_name` wasn't sent:

```json
{
  "summary": { "total": 12, "succeeded": 10, "skipped": 0, "failed": 2 },
  "results": [ … ],
  "list": {
    "list_id": 42,
    "name": "Summer 2026 haul",
    "slug": "summer-2026-haul",
    "items_added": 9
  },
  "dry_run": false
}
```

`list_id` is `null` when the list was requested but never created — dry-run, or zero successful rows — with `items_added: 0` in the latter case. Keeping the object present-but-null-id (rather than collapsing to `null`) lets a caller distinguish "not requested" from "requested, nothing landed."

**New whole-file reason code:** `list_name_taken` — *"a list named 'X' already exists"*. A blank-but-present or over-length `list_name` falls out of `$request->validate()` as Laravel's standard `{message, errors}` 422 instead of the `{reason_code, reason}` envelope. That endpoint already returns both 422 shapes (file validation vs. header), so this isn't new, but the doc should say so plainly.

### Files touched

| Layer | File | Change |
|---|---|---|
| Route | `routes/api.php` | none — same endpoint |
| Controller | `BulkUploadController` | validate `list_name`; pass through; catch the widened exception type |
| Service | `BulkImportService` | `importCsv(…, ?string $listName = null)`; pre-flight collision check; lazy create; per-row item insert + seen-set; `list` block in the return |
| Exceptions | `Services/Exceptions/` | new `BulkImportListNameException` (on the hierarchy from refactor item 1) |
| Models | `BookList`, `ListItem` | none — used as-is |
| Migrations | — | **none.** No schema change. |
| API layer | `api/BulkUploadApi.js` | options-object signature from refactor item 6; append `list_name` only when non-null (keeps the existing call site working) |
| View | `views/BulkUploadView.vue` | `addToList` checkbox + `listName` input; submit disabled while `addToList && !listName.trim()`; render the returned list with a `router-link` to `lists.show` when `list_id` is non-null |
| Stores | — | none. View-local state, matching the rest of this view. `ListsStore.allLists` goes stale, but `ListsView` refetches on mount, so no invalidation step is needed. |

Note the service signature grows a parameter rather than an options object — one optional arg is not worth a DTO yet, but if async processing (improvement 4) lands, the whole call becomes a job payload anyway.

### Tests

New `tests/Feature/BulkUpload/BulkUploadListTest.php`:

- happy path — list created, name/slug correct, owned by the uploader, `items_added` matches
- item order matches CSV row order (`ordinal` 0..n)
- repeated rows for the same version produce **one** list item (the seen-set guard)
- two formats of one book produce two items
- failed rows are absent from the list
- every row failing → **no list created**, `list_id: null`, and the name is free for a retry
- name collides with an existing list → 422 `list_name_taken`, **zero books/versions/read instances written**
- collision is case/slug-based (`My List` vs `my list`)
- collision against *another user's* list of the same name → succeeds (the index is per-user)
- `dry_run=1` with `list_name` → no list, no items, but a taken name still 422s
- omitting `list_name` → `list: null`, behavior otherwise byte-identical to today

`BulkUploadView.vue` has no frontend spec today and this doesn't add one; if the view picks up tests later, the disabled-submit rule is the piece worth pinning.

### Interaction with the other items

- **Consolidation refactor:** items 1 (exception seam) and 6 (API-layer signature) are both prerequisites in practice. Sequence the refactor first.
- **Improvement 1 (surface `dry_run` in the SPA):** the preview request must carry `list_name` too, or the preview validates a different request than the one submitted. Build the checkbox so both paths read the same state.
- **Improvement 4 (async processing):** this is why the list bookkeeping belongs in `BulkImportService`, not the controller. When the loop moves into a queued job, the `list` block travels with the job result and the collision check has to run twice — once synchronously at enqueue time for fast feedback, once inside the job to close the race.
- **Improvement 6 (undo affordance):** a per-upload list is *most* of what the undo item wanted — a record of what one upload brought in. If `bulk_upload_id` later lands on `books`, treat the two as views of the same batch; don't build a second grouping mechanism.
- **Improvement 10 (export roundtrip):** this writes lists **one-way only**. A CSV still cannot express "this row belongs to list X", so lists remain outside the importer's contract and outside the restore path in `/feature-plans/reset-database.md`. Partial mitigation for a single-list reset, not a fix.

### Known limitations this will ship with

- No merge into an existing list — deliberate, see above.
- All-or-nothing per upload: no per-row column to exclude a row from the list.
- No warning when an imported version is already on some *other* list.
- Failed rows are silently absent from the list; the user reconciles against the results table.
- Whole-file 422 on name collision means a long CSV can be rejected after upload but before any work — cheap, but the user re-uploads the file.

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

In rough priority order. (The consolidation refactor and the add-to-a-new-list enhancement are fully designed above and both sit ahead of this list.)

1. **Surface `dry_run` in the SPA.** A "Preview" button on `BulkUploadView` that posts with `dry_run=1` and shows the same per-row results table without writes. Frees the user from having to trust the API alone. Blocked on refactor item 6 — the API layer has no `dry_run` parameter today.
2. **CSV template download.** A `GET /api/bulk-upload/template` returning an empty-but-correct CSV with the new header. Wire a "Download template" button onto `BulkUploadView`.
3. **Add file size and row count limits.** App-layer caps independent of PHP config; reject files over (say) 5MB or 5000 rows with a clear error.
4. **Async processing for large files.** Move the loop into a queued job; return a job id immediately and let the SPA poll a `GET /bulk-upload/jobs/{id}` for progress and results. Required if the row count cap goes above a couple hundred.
5. **Filter / sort the results table.** Tabs or filters for `success` / `failed`. Default to showing failures first when any exist.
6. **Undo affordance.** A per-upload tag (e.g. a `bulk_upload_id` column on `books`) with a 5-minute "I uploaded the wrong file" delete affordance.
7. **Rate limit the route** (e.g. one bulk upload in flight per user, plus a per-day cap).
8. **Coordinate normalization with the rest of the domain.** Genre name normalization (`/feature-plans/genres.md`) and the `FormatHandler` registry (`/feature-plans/books.md` item 6) are still pending; bulk upload must be named in both cutovers.
9. **Finer-grained idempotency reporting.** A re-import currently reports each row as `succeeded` even when zero new rows were written. A `noop` status — or a per-row breakdown of "books / versions / read instances created" — would let a caller verify a re-import didn't silently no-op.
10. **Build a real export endpoint that produces a CSV the importer can roundtrip without loss.** Removes the data-loss surface from any future reset (lists are still not in the importer's contract — see `/feature-plans/reset-database.md`).
11. **Make summary totals balance.** Today blank rows inflate `summary.total` but are not counted as `succeeded`, `failed`, or `skipped`, so `succeeded + failed + skipped != total` for any file with blank rows. Either count blank rows as `skipped` (preferred — matches the field name) or exclude them from `total`. Pinned by `tests/Feature/BulkUpload/BulkUploadTest.php::test_blank_rows_inflate_total_but_are_not_counted_as_skipped`.
