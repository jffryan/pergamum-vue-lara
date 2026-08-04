---
path: /feature-plans/
status: draft
---

# Reset development database

## Goal

The dev database is in a broken state with no backup. Plan a clean `migrate:fresh` followed by re-importing data from CSV via the existing `BulkUploadController`, while neutralizing the footguns that a fresh schema will expose. The goal is a reproducible reset path that doesn't silently break the SPA on the other side.

## What survives a reset and what doesn't

The bulk importer (`BulkUploadController::upload` → `BulkImportService::importCsv`) reads a header-named CSV (columns: `title, authors, format, page_count, audio_runtime, version_nickname, genres, date_read, rating`) and creates: `Book` (slug-deduped), `Author`s (slug-deduped, multi-author per row), `Genre`s (case-insensitive dedupe), one or more `Version`s per book (deduped on `(book_id, format_id, version_nickname)`), and one `ReadInstance` per row that has `date_read` set. The CSV contract and per-row failure shape are documented in `/documentation/bulk-upload.md`.

What does NOT roundtrip through the importer and must be recreated manually or via separate seed/import:

- **`User`s** — the importer requires `auth()->id()` and writes `read_instances.user_id` from the session. Register a user via `POST /register` (web route) before importing.
- **`List`s and `ListItem`s** — no CSV column, no importer code path. All user-curated lists are lost.
- **`Book.nickname`** — there is no CSV column for it. `version_nickname` does roundtrip; `Book.nickname` does not.
- **`Format`s themselves** — the importer looks up formats by name and FAILS the row if no match. A fresh DB has zero format rows, so every row fails until formats are seeded first.
- **Slugs on `formats`** — only `POST /formats` (admin UI) populates `slug`. A seeder that inserts via `Format::create(['name' => ...])` without `slug` will leave `slug = null`, which silently breaks the `/formats/:slug` browse route (see footgun §3).

Multi-read history, multi-version books, multi-author rows, and audio runtimes all roundtrip cleanly under the new contract.

## Footguns the fresh DB will expose

### 1. `formats.slug` must be populated, not just `name`

Frontend routes link to `/formats/:slug` using `bookFormat.slug` from `BookTableRow`. The seeder must set `slug` explicitly (e.g. `Str::slug($name)`) — `formats.slug` is nullable in the migration and there is no auto-derive on `Format::create`. Without slugs, the browse route silently shows nothing.

Note also the existing slug-vs-name browse bug documented in `/feature-plans/formats.md` item 1: `BookController::index` filters `?format=` by `name`, not `slug`. For single-word formats (`Audiobook`, `Paper`, `Ebook`) the slug equals the lowercased name and matches under MySQL's default case-insensitive collation by accident. Multi-word formats won't work. A reset is not the moment to fix this — but if the user adds a multi-word format ("Graphic Novel") to the seeder, it will not be browsable until the slug-vs-name bug is fixed.

### 4. No users, no admin role

`migrate:fresh` empties `users`. The importer requires an authenticated session (`auth()->id()`) for `read_instances.user_id`. Steps:

1. `POST /register` (web route, `routes/web.php:19`) to create the user.
2. Log in via `POST /login`.
3. Hit `GET /sanctum/csrf-cookie`.
4. Run the import while authenticated.

There is no `is_admin` column; "admin" pages are admin only by URL convention. After reset, the new user is implicitly an admin.

### 5. (resolved) Bulk importer slug, multi-read, multi-version, multi-author, genre, audio-runtime issues

All folded into the bulk-upload hardening shipped 2026-04-29 (see `/documentation/bulk-upload.md` and the `CHANGELOG.md` entry). The new `BulkImportService`:

- uses `Str::slug($title)` for book slugs (no truncation suffix);
- creates a new `ReadInstance` per row when `date_read` is set, against the resolved version;
- dedupes versions on `(book_id, format_id, version_nickname)`, so paper + audiobook of the same title is two rows;
- accepts `;`-separated `authors` entries (`First|Last;First|Last`) so co-authors roundtrip;
- normalizes genre lookup case- and whitespace-insensitively;
- accepts `audio_runtime` as a real CSV column.

The reset can rely on these without inline patches. What remains is the seeding work (Step 2) and the user/login bootstrap (Step 4).

### 6. `ListItem` references `version_id`, not `book_id`

Even if lists were re-importable from a separate CSV, the `version_id`s won't match across a reset (auto-increment resets). Any list re-import would have to look up versions by `(book.slug, format.name)` and resolve to the new `version_id`. There is no list export today; lists are gone.

### 7. `personal_access_tokens` and `password_resets` tables

Auth is Sanctum SPA-cookie, not token-based. The `personal_access_tokens` table is unused. `password_resets` (Laravel 9 name) was renamed to `password_reset_tokens` in Laravel 10 but the migration here still creates `password_resets`. Not a reset blocker, but worth a follow-up.

### 8. Tests bind to specific data shapes

`tests/Feature/Books/BooksCrudTest.php:106` references `format->format_id` from a created factory, which is fine. But broader tests use `RefreshDatabase` and create their own data — they do NOT depend on the dev DB. Run `php artisan test` after the reset to confirm the schema migrates clean.

### 9. `ConfigStore.books.formats` is loaded once per session

The SPA caches formats. Anyone with the app open during the reset will see an empty `<select>` until they hard-reload. Not a reset blocker, just close all open tabs.

## Approach

### Step 1 — Pre-reset prep

1. **Export the current DB to CSV** before doing anything destructive, even from the broken state, in case rows are still readable. `mysqldump` the full DB to a `.sql` file too as a belt-and-suspenders backup.
2. **Audit the CSV** before importing:
   - Format names exactly match `FormatSeeder::FORMATS` (`Physical`, `Audiobook`, `Pirated`, `Ebook`, `Graphic Novel`). The lookup is case-insensitive, but an unmatched name fails the row.
   - Author entries use the `First|Last` shape and use `;` to separate co-authors.
   - Genres use `;` to separate entries; case differences are fine (the importer dedupes case-insensitively).
   - Rows whose format declares `expects_audio_runtime` have `audio_runtime` set; rows whose format declares `expects_page_count` have `page_count` set. A value a format doesn't carry is discarded rather than stored, so a stray `page_count` on an audiobook row is harmless.
   - Re-reads are represented as separate rows with the same `(title, format, version_nickname)` and different `date_read`.
3. **Dry-run the import** before committing: `POST /api/bulk-upload` with `dry_run=1` to surface per-row failures without touching the DB. Iterate on the CSV until the dry-run summary is clean.

### Step 2 — Review the seeder's format list

`database/seeders/FormatSeeder.php` is wired into `DatabaseSeeder`, so `migrate:fresh --seed` is enough. Before the reset, check the set it seeds (Physical / Audiobook / Pirated / Ebook / Graphic Novel) is the set you want carried forward — drop any you don't, and make sure the CSV doesn't reference them, because an unmatched format fails the row.

### Step 3 — Reset and reseed

```bash
docker compose exec php php artisan migrate:fresh --seed
```

Confirm: `formats` holds the seeded set; everything else is empty.

### Step 4 — Register a user, then import

1. Hit `POST /register` (or use the SPA register flow).
2. Log in.
3. `POST /api/bulk-upload` with the prepped CSV (drop `dry_run` for the real run).
4. Spot-check: `Book` / `Author` / `Genre` / `Version` counts match expectations; `ReadInstance` count exceeds the book count where re-reads exist; audiobook versions have a non-null `audio_runtime`.

### Step 5 — Manually rebuild lists

Lists are gone. Recreate the canonical ones via the SPA. Document the list of lists in this plan if there are more than a couple, so a future reset is faster.

## Touches existing systems

- `database/seeders/DatabaseSeeder.php` and `database/seeders/FormatSeeder.php` — both now exist; the reset only consumes them.
- The book / version forms no longer assume anything about format ids or names, so the reset is free to reassign ids. See `/documentation/formats.md`.
- `books.slug` and `authors.slug` are both uniquely indexed at the DB level (the latter via `2026_04_30_000000_make_authors_slug_unique_and_required.php`), so the bulk importer's find-or-create-by-slug logic is backstopped. Pre-existing duplicates have already been surfaced and resolved by that migration rather than mid-restore.

## Open questions

1. **Lists re-import** — out of scope for this reset. Worth an export-lists / import-lists pair to avoid this on the next reset? Track as a follow-up plan if so.
2. **Should `formats.slug` become non-null + unique as part of the reset?** The seeder populates it, so the data will be clean. But the migration still allows null. Tightening the schema is `/feature-plans/formats.md` item 7 — defer.

## Future improvements

- Build a real export endpoint that produces a CSV the importer can roundtrip without loss (lists in particular are still gone). Removes the data-loss surface from any future reset.

## Known limitations

- Lists are not preserved across the reset.
- `Book.nickname` is still not in the CSV contract and does not survive.
