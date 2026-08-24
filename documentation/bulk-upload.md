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

- **Route** (`routes/api.php`, `auth:sanctum`): `POST /bulk-upload` → `BulkUploadController::upload`. Accepts a multipart form with field `csv_file` and optional `dry_run` / `list_name` / `create_locations` fields.
- **Controller**: `BulkUploadController` — thin handler. Validates the request, calls `BulkImportService`, catches `BulkImportFileException` for the 422 path.
- **Service**: `app/Services/BulkImportService.php` — owns parsing, header validation, per-row processing, transactions, and result aggregation. Public surface is `importCsv(UploadedFile $file, int $userId, bool $dryRun = false, ?string $listName = null, bool $createLocations = false): array` plus `validateRow(array $cells, ?Format $format): ImportRow`, which is pure (no file handle, no database) and has its own `tests/Unit` spec.
- **Supporting types** (`app/Services/BulkImport/`): `ImportRow`, a readonly value object carrying one validated row from `validateRow` to the persist step; `ListCollector`, which owns the lazy list creation, the `ordinal` counter, and the per-row dedupe for the `list_name` feature.
- **Exceptions** (`app/Services/Exceptions/`): abstract `BulkImportException` holds a `reasonCode` and splits on blast radius, not cause. `BulkImportFileException` rejects the whole upload and is what the controller catches (`BulkImportHeaderException`, `BulkImportListNameException`); `BulkImportRowException` fails one row inside the loop and is deliberately *not* caught by the controller, so a row failure can never escape as a 422.
- **Models**: creates `Book`, `Version`, `ReadInstance`, and — when `list_name` is sent — `BookList` and `ListItem`; finds-or-creates `Author` and `Genre`. `Format` is read-only (existing rows only — no auto-create).
- **Policies / authorization**: none beyond `auth:sanctum`.
- **Migrations**: writes the same tables documented in `books.md`, `authors.md`, `genres.md`, `formats.md`. No bulk-upload-specific schema.

### Frontend

- **API layer**: `resources/js/api/BulkUploadApi.js` — `bulkUpload(file, { dryRun = false, listName = null } = {})` builds a `FormData`, appending the optional fields only when set, , sets `Content-Type: multipart/form-data`, and calls `axios.post('/api/bulk-upload', …)` directly. Does **not** route through `apiHelpers.js` (`makeRequest` / `buildUrl`) — multipart bodies don't fit `makeRequest`'s JSON shape, so this is the one acceptable bypass.
- **Stores**: none. State lives view-locally in `BulkUploadView.data()`.
- **Routes**: `router/book-routes.js` defines `/bulk-upload` (named `books.bulk-upload`).
- **Views**: `views/BulkUploadView.vue` — file input, an "add to a new list" checkbox with its name field, submit button, and a per-row results table colored by status. Submit is disabled while the checkbox is on and the name is blank. Renders `reason` (human string) when a row fails. On a whole-file rejection it reads `reason` first, then `message` (Laravel's own request-validation 422 shape), then a generic fallback.

## CSV contract

### Columns

Header is **required and validated by name**. Column order is irrelevant. Header comparison is case-insensitive and trims whitespace. Unknown column names are rejected (`reason_code: header_invalid`). Missing required columns are rejected the same way. The order in which the columns appear in your file does not matter; only the names do.

**Column presence and value requirements are separate questions.** Only `title`, `authors`, and `format` must appear in the header. Every other column may be omitted entirely. Whether a *value* is required is decided per row, by the row's format — a physical row still needs a `page_count`, and it fails with `page_count_required` whether the cell is blank or the column is absent. A file of nothing but physical books therefore does not need to carry an empty `audio_runtime` column.

The "Column required" figure below is about the header only.

| Column            | Column required | Notes |
|-------------------|----------|-------|
| `title`           | yes      | Used to derive `Book.slug` (`App\Support\Slugger`). |
| `authors`         | yes      | `;`-separated list of entries; each entry is `First\|Last`. Single-author rows use one entry. Empty `First` or empty `Last` are allowed (one of the two must be non-empty per entry). |
| `format`          | yes      | Looked up case-insensitively in `formats.name`. Must already exist; bulk upload does not auto-create formats. |
| `page_count`      | no       | Integer. The *value* is required when the row's format declares `expects_page_count` (`page_count_required`). Blank or absent is fine otherwise, and the version stores no page count. A value supplied for a format that doesn't carry one is discarded. |
| `audio_runtime`   | no       | Integer minutes. The *value* is required when the row's format declares `expects_audio_runtime` (`audio_runtime_required`). Blank or absent otherwise, and a value supplied for a format that doesn't carry one is discarded. |
| `version_nickname`| no       | Free text; disambiguates versions sharing `(book, format)` (e.g., two paperbacks). |
| `genres`          | no       | `;`-separated list. Lookup is case-insensitive and trims whitespace; `Fantasy`, `fantasy`, ` Fantasy ` all dedupe to the existing genre. |
| `date_read`       | no       | Accepts `Y-m-d`, `n/j/Y`, `m/d/Y`. Blank means "no read instance for this row." |
| `rating`          | no       | Decimal 0.5–5 in 0.5 steps. The `ReadInstance` mutator doubles the value on insert (a CSV value of `4.5` is stored as `9`). |
| `is_discarded`    | no       | Boolean: `1` / `true` / `yes` / `y`, or blank / `0` / `false` / `no` / `n`. Case-insensitive. Anything else fails the row (`is_discarded_invalid`). Applied on version *create* only. |
| `discarded_at`    | no       | Same date formats as `date_read`. Only meaningful with `is_discarded` set — a date without the flag fails the row (`discarded_at_without_flag`). Blank with the flag set is the "discarded, date unknown" case. |
| `location`        | no       | One `CODE` or `CODE\|ordinal` entry — a copy has exactly one place, so a `;` fails the row (`location_entry_malformed`). The code is matched to `locations.slug` case-insensitively; an unknown code fails the row (`location_not_found`) unless `create_locations` was sent. Applied on version *create* only. The ordinal is the copy's left-to-right shelf position. |
| `lists`           | no       | `;`-separated list of `Name` or `Name\|ordinal` entries. Files the row's version onto each named list, owned by the importing user. A non-numeric ordinal or a name that slugs to nothing fails the row (`list_entry_malformed`). |

The column vocabulary lives in `App\Support\CsvContract` and is shared by the
reader and the writer, so the importer and the exporter cannot drift apart.

### Encoding choices

- `;` between list entries, `|` between author name parts. Avoids CSV-comma-quoting traps.
- One `authors` column rather than separate first/last columns — symmetric for single-author and multi-author rows.

### Row semantics

Each row describes one (book, version, optional read instance). Rows are de-duped against existing rows at each layer:

1. **Book**: find-or-create by `slug = Slugger::for(title)`. Title on existing books is left alone.
2. **Authors**: each entry → `AuthorService::attachToBook`, which find-or-creates by `AuthorService::slugFor($first, $last)`, attaches if not already attached, and continues the co-author ordinal from the book's current max. These were the importer's own semantics until the book forms adopted them; they now live in the service and all four doors share them. Whether an entry is *acceptable* — one of the two halves non-empty — is `parseAuthors`, matching `ValidatesAuthorNames` on the form doors.
3. **Genres**: each entry → find by `LOWER(TRIM(name))` first; if none, create with the trimmed (case-preserved) value. Attached if not already attached.
4. **Version**: find-or-create by `(book_id, format_id, version_nickname)`. `audio_runtime`, `page_count`, `is_discarded`, `discarded_at`, `location_id` and `shelf_ordinal` are written on create; on existing-version match they are left alone (so re-imports don't overwrite hand edits, an older file can't resurrect a copy you got rid of after writing it, and a stale file can't reshelve a copy you moved). Because the version dedupe tuple is also the copy identity, two rows sharing `(title, format, nickname)` but naming **different** locations describe two physical copies the importer would collapse into one — the later row fails (`ambiguous_copy`); give the copies distinguishing nicknames.
5. **Read instance**: if `date_read` is non-blank, always create a new `ReadInstance` against the resolved version with `user_id = auth()->id()`. Multiple rows with the same (title, format, nickname) but different dates produce multiple read instances — re-reads roundtrip cleanly.
6. **List membership**: each `lists` entry → find-or-create a `BookList` by `(user_id, Str::slug(name))`, then append the resolved version if `(list_id, version_id)` isn't already taken. An ordinal in the entry is used verbatim; without one the item appends at `max(ordinal) + 1`. Nothing is written under `dry_run`.

This single-row shape covers every restore scenario:

- One-time read of a paperback: one row.
- Re-read of the same paperback three times: three rows, identical except `date_read` / `rating`.
- Same book in paperback and audiobook: two rows, different `format` (and `audio_runtime` on the audiobook row).
- Owned but unread: one row with blank `date_read` and `rating`.
- Two co-authors: one row, `authors = "Jane|Smith;John|Doe"`.

## Submitting

`POST /api/bulk-upload` multipart:

| Field | | |
|---|---|---|
| `csv_file` | file | required |
| `dry_run` | boolean | optional |
| `list_name` | string, max 255 | optional — see [Filing an import into a new list](#filing-an-import-into-a-new-list) |

Successful (or partially-successful) response, HTTP 200:

```json
{
  "summary": { "total": 12, "succeeded": 10, "skipped": 0, "failed": 2 },
  "results": [
    { "row": 1, "title": "Dune", "status": "success" },
    { "row": 2, "title": "Lost", "status": "failed", "reason_code": "format_not_found", "reason": "format 'eBook' not found" }
  ],
  "list": null,
  "dry_run": false
}
```

Whole-file rejection, HTTP 422:

```json
{ "reason_code": "header_invalid", "reason": "Missing required column(s): authors" }
```

Note this endpoint returns **two different 422 shapes**. Whole-file rejections raised by the importer use `{reason_code, reason}`; failures of `$request->validate()` (no file, wrong mime type, blank or over-length `list_name`) use Laravel's standard `{message, errors}`. `BulkUploadView` reads `reason` first and falls back to `message`.

### Whole-file `reason_code` values

| Code | Meaning |
|---|---|
| `header_invalid` | A required column is missing, or an unknown or duplicate column is present. |
| `list_name_taken` | `list_name` was sent and the user already owns a list with that slug. Nothing is imported. |

`summary.skipped` is always `0` in the new contract — finer-grained idempotency reporting (rows that produced zero new writes) is tracked as a future improvement on `/feature-plans/bulk-upload.md`. Re-uploading a CSV simply reports each row as `success` while reusing existing books / authors / versions.

### Per-row `reason_code` values

| Code                        | Meaning |
|-----------------------------|---------|
| `missing_required_field`    | `title`, `authors`, or `format` was blank. |
| `format_not_found`          | `format` did not match any row in `formats` (case-insensitive). |
| `audio_runtime_required`    | The row's format declares `expects_audio_runtime` and the cell was blank. |
| `page_count_required`       | The row's format declares `expects_page_count` and the cell was blank. |
| `date_parse_failed`         | `date_read` did not match `Y-m-d`, `n/j/Y`, or `m/d/Y`. |
| `rating_out_of_range`       | `rating` was outside 0.5–5 or not a half-step. |
| `rating_not_numeric`        | `rating` was non-numeric. |
| `author_entry_malformed`    | An entry in `authors` lacked a `\|` or had both halves blank. |
| `is_discarded_invalid`      | `is_discarded` was not one of the recognized boolean spellings. |
| `discarded_at_without_flag` | `discarded_at` carried a date while `is_discarded` was blank or false. |
| `list_entry_malformed`      | An entry in `lists` had a non-numeric ordinal, or a name that slugs to nothing. |
| `location_entry_malformed`  | `location` held more than one value, an empty code, or a non-numeric ordinal. |
| `location_not_found`        | `location` named a code no location holds, and `create_locations` was not sent. |
| `ambiguous_copy`            | The row names a different location than an earlier row with the same `(title, format, version_nickname)` — two copies the version dedupe would collapse. Fix with distinguishing nicknames. |
| `internal_error`            | An unexpected exception fired inside the row's transaction. The exception is logged via `Log::error`; the response carries a generic message. |

Whole-file codes are returned at 422 and never appear in the per-row `results` array.

## Creating locations on import (`create_locations`)

By default an unknown `location` code fails its row: locations are physical
places, and a typo must not silently invent a shelf. That default breaks one
legitimate case — the database reset, where `migrate:fresh` has emptied the
`locations` table and the export being restored names shelves that no longer
exist as rows.

Sending `create_locations` (boolean) opts in: an unknown code is created via
`LocationService::findOrCreateByCode`. A code matching the shelf convention
(`O1S5`) rebuilds its room → bookcase → shelf chain exactly the way the
backfill migration did; any other code lands as a root location of kind
`shelf`, with no structure guessed. Location `name`s ('Office') are not in the
CSV and come back null — re-enter them after a reset. Creation happens inside
the row's transaction, so a `dry_run` with the flag writes nothing.

## Filing an import into a new list

Sending `list_name` files every version the import found or created on a **successful** row into one brand-new list owned by the uploader. On `BulkUploadView` this is a checkbox (default off) that reveals a required name field; submit stays disabled while the box is checked and the field is blank.

The response grows a top-level `list` block, `null` when `list_name` wasn't sent:

```json
"list": {
  "list_id": 42,
  "name": "Summer 2026 haul",
  "slug": "summer-2026-haul",
  "items_added": 9
}
```

Rules, all deliberate:

- **New list only, never a merge into an existing one.** Merging would need reconciliation semantics the importer has no basis to resolve (append vs. dedupe, where new items land in an existing `ordinal` sequence, what "already on the list" means for a row that matched an existing version). To get rows into an existing list, import into a new one and move the items with the list UI.
- **The name must be free, and the check runs first.** `lists` is unique on `(user_id, slug)`. The collision check happens *before the CSV is even opened*, so a request that is both name-colliding and header-invalid reports `list_name_taken`. On collision nothing is imported at all — a half-imported CSV attached to nothing is the worse outcome. The user renames and re-submits.
- **The collision is on slug, not raw name.** `My List` and `my list` collide. Slugs come from `Str::slug`, matching `ListController::store` exactly, so the list this creates is indistinguishable from a hand-created one. The index is per-user, so another user's list of the same name does not collide.
- **Versions, not books.** `list_items.version_id` is the FK, and a row resolves to exactly one version. A book imported in two formats across two rows produces two list items.
- **Found and created versions both count.** A re-import that creates nothing still files everything into the new list — the list reflects the CSV's contents, not the delta.
- **The list is created lazily,** inside the first successful row's transaction. An import where every row fails creates nothing, returns `list_id: null` with `items_added: 0`, and leaves the name free for a retry — no orphan list to delete first. Later per-row rollbacks don't touch the already-committed list, so a partially-successful import yields a partial list, consistent with the importer's existing partial-write design.
- **`ordinal` is a running counter from 0,** so list order is CSV row order.
- **Repeated rows for one version produce one item.** Three re-read rows for the same paperback resolve to the same version, and `(list_id, version_id)` is uniquely indexed — the in-memory dedupe is what keeps the second row from throwing and being reported as `internal_error`.
- **Failed rows contribute nothing.** The user cross-references the results table to see what didn't make it.

`list_id` is `null` when the list was requested but never created — a dry run, or an import with no successful rows. The block stays present-but-null-id rather than collapsing to `null` so a caller can tell "not requested" from "requested, nothing landed."

`list_name` is distinct from the per-row `lists` column. `list_name` files a
whole import into one **brand-new** list and rejects a name already taken;
`lists` restores membership a file already describes, so an existing list of the
same name is the target rather than a collision. Both can be sent at once — a
row then lands on the lists it names *and* on the new one.

## Dry run

`POST /api/bulk-upload` with `dry_run=1` (or `true`) runs every row through the same code path, but rolls back each row's transaction instead of committing. The response shape is identical to a real run with `"dry_run": true` echoed back.

One subtlety: find-or-create inside a dry-run row sees rows created by *earlier* dry-run rows only within that row's transaction (which is then rolled back). A dry-run of a CSV that would create the same author across two rows reports two creates on the second row's lookup-then-create path inside its own transaction. The summary counts in dry-run can therefore be slightly off for cross-row dedupe, but per-row failures (the thing dry-run exists to catch) are accurate.

With `list_name`, a dry run creates neither the list nor any items, and the collision check still runs — so a preview catches a taken name before the real submit. `list.items_added` **is** exact under dry-run: the item dedupe is keyed on the version's identity tuple (book slug, format, nickname) rather than on `version_id`, precisely because a rolled-back row re-creates the same version with a fresh id on the next row. That is why this count does not inherit the cross-row caveat above.

The header-invalid 422 path is unaffected by `dry_run`.

## Non-obvious decisions and gotchas

- **Per-row transactions, not whole-file.** Each row runs its own `DB::beginTransaction` / `DB::commit`; a failing row rolls back its own writes only and the loop continues. There is no all-or-nothing mode — partial imports are the design. Callers must inspect `summary` and `results` to decide what to do.
- **`fclose($handle)` is in a `finally`.** A truly unexpected exception escaping the per-row catch will not leak the file handle.
- **Format lookup is case-insensitive via `whereRaw('LOWER(name) = ?', …)`.** Non-index-friendly at scale, but the `formats` table has fewer than a dozen rows in practice. Bulk upload does not create new formats — unknown format names fail the row.
- **Which length value a row must carry comes from the format's capability flags**, not from its name. `BulkImportService::validateRow` reads `expects_page_count` / `expects_audio_runtime` off the resolved `Format`, so a second spoken-word medium is a row in `formats` rather than another `strcasecmp` against `'Audiobook'`. The two checks are independent — a format may legitimately demand both. See `/documentation/formats.md`.
- **A length value the format doesn't carry is dropped, not stored.** An audiobook row with a `page_count` cell filled in stores no page count, because `estimatedTotalPagesByYear` already folds that version's runtime into pages and would otherwise count it twice. `versions.page_count` is nullable as of `2026_08_02_000002`; the old convention of storing `0` for audiobooks is gone, and both read as zero to every `SUM`.
- **Book and author slugs come from `App\Support\Slugger`,** the same helper the SPA's creation paths use — 60-character cap, truncated at a hyphen boundary. A slug match means "same book" / "same author", so the importer does *not* use `BookCreator::create`, which suffixes `-2`/`-3` on collision. `lists.slug` and `formats.slug` are different entities and derive from raw `Str::slug`.
- **List slugs use `Str::slug`, not `Slugger`.** `Slugger` (60-char cap) governs books and authors. `lists.slug` follows `ListController::store` so a bulk-created list is byte-identical to a hand-created one, and list slugs are unique per user rather than globally. `formats.slug` is likewise plain `Str::slug`. This is not drift.
- **`ListCollector` snapshots its bookkeeping per row.** A row that throws rolls its transaction back, and the collector restores the seen-set, the `ordinal`, *and* its list handle. Snapshotting the handle is what distinguishes a list created inside the rolled-back row (dropped, so the next successful row creates it again) from one committed by an earlier row (kept — otherwise the next row would try to create a second list with the same slug and hit the per-user unique index).
- **Genre case dedupe stores the trimmed input as-typed.** The lookup is case- and whitespace-insensitive, but if a genre is being created for the first time the value persisted is whatever the row supplied (after `trim`).
- **Version dedupe key is `(book_id, format_id, version_nickname)`.** Two paperback rows with different `version_nickname` values produce two versions; two paperback rows with the same blank nickname produce one shared version.
- **Read instances are *always* created when `date_read` is set.** There is no dedupe on `(version_id, user_id, date_read)` — a CSV with two identical rows including the same `date_read` will create two `ReadInstance`s. This is intentional: the importer cannot tell whether the duplicate is an actual re-read recorded twice or a CSV mistake. Audit your CSV before importing if duplicates would be a problem.
- **`internal_error` does not leak exception messages.** The catch block logs the exception via `Log::error` with row context, then returns a generic message in the response. SQL driver text and Carbon parse errors do not appear in the JSON.
- **No file size or row count limit at the app layer.** Inherits PHP's `upload_max_filesize` / `post_max_size`. Tracked as a future improvement.
- **No async / job queue.** The request runs synchronously and blocks until the whole file is processed. Tracked as a future improvement.

## Export

`GET /api/export` (`auth:sanctum`) is the inverse of the importer and emits
**exactly** the contract above — every column in `CsvContract::COLUMNS`, in that
order — so an export is a valid upload with no translation step.

- **Route / controller**: `routes/api.php` → `ExportController::download`, which streams the file via `response()->streamDownload` and `fputcsv`. Filename is `pergamum-export-<Y-m-d>.csv`.
- **Service**: `app/Services/CatalogExportService.php`. `rows(int $userId): Generator` yields the header then one row per (version, read) pair, iterating books with `lazyById(200)` — not `cursor()`, which resolves each eager load per record and trades the memory saving for an N+1. Row order follows `book_id`.
- **Frontend**: `exportCatalog()` in `resources/js/api/BulkUploadApi.js` (blob response), surfaced as a "Download export" button in the Export section of `BulkUploadView`.

What it emits:

- **The whole catalog**, not just the caller's: books, authors, genres, versions, and each version's discard state are shared, so they all cross.
- **The caller's reading state only**: read instances come out user-scoped (`BelongsToCurrentUser` on the model), and `lists` carries only lists the caller owns. Exporting from account B never reveals account A's reads.
- **One row per (version, read).** A version read three times yields three rows differing only in `date_read` / `rating`; a version never read yields one row with both blank, so an owned-but-unread copy survives.
- **List membership on the first row of each version only.** It is a property of the version, not of any one read of it — repeating it on every row would state the same claim three times.
- **`rating` halved back to the 0–5 display scale.** `ReadInstance::setRatingAttribute` doubles on write and nothing halves on read, so without this a roundtrip would double the rating on every pass.

### Round trip

`tests/Feature/Export/CatalogRoundtripTest` is the guarantee: it builds a catalog
exercising every column, exports it, deletes everything but formats and users,
re-imports, and asserts the result is equal to what it started with. A field that
stops surviving a reset fails that test rather than being discovered during one.

The runbook that uses this is `/documentation/database-reset.md`.

## Related

- Plan file: `/feature-plans/bulk-upload.md` — remaining limitations and future improvements (auth/role, rate limit, async, frontend template/preview/undo, performance batching).
- `/documentation/database-reset.md` — the reset procedure this pair exists to make safe.
- `/documentation/books.md` — `Book` / `Version` / `ReadInstance` schema, custom PKs, rating mutator, slug rules.
- `/documentation/new-book-creation.md` — the interactive creation flow this surface bypasses entirely.
- `/documentation/authors.md`, `/documentation/genres.md`, `/documentation/formats.md` — taxonomy attached / matched per row.
