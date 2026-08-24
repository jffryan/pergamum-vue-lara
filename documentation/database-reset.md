---
path: /documentation/
status: living
---

# Resetting the development database

## Scope

The procedure for taking the dev database down to bare schema and putting the
data back: `migrate:fresh --seed`, then a CSV import through the bulk-upload
surface. Covers what survives, what doesn't, and the order the steps have to
happen in.

The CSV contract itself is `/documentation/bulk-upload.md`; this document is the
procedure, not the format.

## Summary

`docker compose exec php php artisan migrate:fresh --seed` empties everything
and reseeds `formats`. Everything else comes back through
`POST /api/bulk-upload`, from a file produced by `GET /api/export`. Because the
exporter emits the importer's exact contract, a reset is lossless for everything
the CSV carries — which, since the export landed, is everything except users.

## What survives a reset

Nothing in the database survives `migrate:fresh` itself. What survives *the
procedure* is whatever the export file carries:

| | Roundtrips | How |
|---|---|---|
| Books, slugs | yes | `title` (slug re-derived by `Slugger`) |
| Authors, co-authors, ordinal | yes | `authors`, `;`-separated `First\|Last` |
| Genres | yes | `genres` |
| Versions, page counts, runtimes, nicknames | yes | `format`, `page_count`, `audio_runtime`, `version_nickname` |
| Discarded copies and their dates | yes | `is_discarded`, `discarded_at` |
| Read history, re-reads, ratings | yes | `date_read`, `rating` (one row per read) |
| Lists, list membership, item order | yes | `lists`, `;`-separated `Name\|ordinal` |
| Shelf assignments and shelf order | yes | `location`, `CODE` or `CODE\|ordinal` — **requires `create_locations` on the import**, see step 4 |
| **Location names** (`'Office'`) | **no** | Codes rebuild the tree; names aren't in the CSV. Re-enter them after import |
| **Users** | **no** | Register before importing — see step 3 |

Primary keys are *not* preserved. Every `book_id`, `version_id`, `list_id` and
`read_instance_id` is reassigned on import. Nothing in the app persists an id
outside the database, so this only matters if you have bookmarked a URL that
routes by id — `/genres/:id` is the one that does.

## Procedure

### 1. Export first

While the app still runs, hit **Download export** on `/bulk-upload` (or
`GET /api/export`). Take a `mysqldump` as well — the export carries what the CSV
contract covers, and a SQL dump carries the rest.

Export *before* anything destructive, even from a database you believe is
broken; a partial export beats none.

### 2. Reset and reseed

```bash
docker compose exec php php artisan migrate:fresh --seed
```

`DatabaseSeeder` runs `FormatSeeder`, which `updateOrCreate`s the canonical
formats (Physical, Audiobook, Pirated, Ebook, Graphic Novel) **with their slugs
and capability flags**. This ordering is load-bearing: the importer looks
formats up by name and fails every row if none exist, and `/formats/:slug`
silently shows nothing for a format whose slug is null.

Confirm `formats` holds the seeded set and everything else is empty.

### 3. Register a user

`migrate:fresh` empties `users`, and the importer writes
`read_instances.user_id` from the session — so there must be a session.

1. `POST /register` (`routes/web.php`), or the SPA register flow.
2. Log in (`POST /login`).
3. `GET /sanctum/csrf-cookie`.

There is no `is_admin` column; admin pages are admin-only by URL convention, so
the new user is implicitly an admin.

### 4. Dry-run, then import

```
POST /api/bulk-upload   csv_file=<export.csv>   dry_run=1   create_locations=1
```

The dry run surfaces per-row failures without writing. Iterate until the summary
is clean, then re-post without `dry_run`.

**`create_locations=1` is required for a reset import.** `migrate:fresh` empties
`locations`, and without the flag every row carrying a `location` code fails
with `location_not_found` — by design, since on a *populated* database an
unknown code is a typo, not a missing shelf. The flag rebuilds each shelf
code's room → bookcase → shelf chain; see `/documentation/bulk-upload.md`.

If the file came from `GET /api/export` it should be clean on the first pass. A
hand-edited file is where the per-row `reason_code` values in
`/documentation/bulk-upload.md` earn their keep.

### 5. Spot-check

- `Book` / `Author` / `Genre` / `Version` counts match the pre-reset numbers.
- `ReadInstance` count exceeds the book count wherever re-reads exist.
- Audiobook versions have a non-null `audio_runtime` and a null `page_count`.
- Discarded copies still read as discarded (`/library?discarded=only`).
- Lists exist with their items in the original order.
- `php artisan test` passes, confirming the schema migrated clean.

## Non-obvious decisions and gotchas

- **Export before reset is the whole procedure.** Everything else is mechanics. The one irreversible mistake is running `migrate:fresh` first.
- **Users don't roundtrip and won't.** The CSV has no user columns and shouldn't — it would mean putting password hashes in a file people email around. Registering is one step; carrying credentials through a data file is a standing hazard.
- **Ids are reassigned.** See above. `/genres/:id` is the only id-routed surface; it is tracked for a slug in `/feature-plans/genres.md`.
- **A re-import onto a *populated* database is a merge, not a replace.** Every layer is find-or-create, and existing versions keep the field values they already have. That makes re-importing safe but means it cannot be used to push corrections — a CSV that fixes a wrong page count is a no-op against an existing version.
- **The SPA caches formats for the session.** `ConfigStore.books.formats` loads once. Anyone with the app open across the reset sees an empty format `<select>` until they hard-reload. Close open tabs first.
- **`personal_access_tokens` is created but unused.** Auth is Sanctum SPA cookie/session. Likewise the migration still creates `password_resets` (the Laravel 9 name) rather than Laravel 10's `password_reset_tokens`. Neither blocks a reset; both are noted in `/feature-plans/auth.md`.
- **Tests don't depend on the dev database.** They use `RefreshDatabase` and build their own fixtures, so a broken dev database never explains a failing suite.

## Related

- `/documentation/bulk-upload.md` — the CSV contract, the export endpoint, and the roundtrip test that backs this procedure.
- `/documentation/formats.md` — `FormatSeeder` and the capability flags it writes.
- `/documentation/auth.md` — registration and the Sanctum session this procedure needs.
