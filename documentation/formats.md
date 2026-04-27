---
path: /documentation/
status: living
---

# Formats

## Scope

Covers the `Format` model, the format-bootstrap config endpoint, the `POST /formats` admin create endpoint, the `/formats/:format` browse view, and the admin format-management surface. Format *attachment* to versions during book / version creation belongs to the book pipeline (`books.md`) and is summarized here only enough to point at the right code.

## Summary

A `Format` is a flat lookup record (`format_id`, `name`, optional `slug`) describing the medium of a `Version` — Audiobook, Paper, Ebook, etc. Unlike `Author` and `Genre`, formats are never created as a side effect of book creation: they are pre-existing config that the SPA bootstraps from `GET /api/config/formats` and that the new-book / edit-book / add-version forms select from. New formats are added explicitly via the admin surface (`POST /formats`). Books are reachable by format at `/formats/:slug`, which reuses the books index endpoint with a `?format=` filter.

```
Format ─< Version >─ Book        (Format::books() is a through-versions belongsToMany)
```

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `GET /config/formats` → `ConfigController::getFormats` — lightweight `[{format_id, name}, ...]` payload the SPA caches in `ConfigStore`. Lives under `/config/` because formats are treated as bootstrap config, not as a queryable taxonomy.
  - `POST /formats` → `FormatController::store` — admin-style create. Validates `name` as `required|string|max:255|unique:formats,name`, derives `slug` via `Str::slug($name)`, returns the full row with 201.
  - There is **no** `Route::resource('/formats', ...)`. There is also no GET-by-id, update, or destroy endpoint. Browsing books *by* format goes through `GET /books?format=<name>`, handled by `BookController::index`.
- **Controllers**: `FormatController` is a single-action store (no constructor, no service). `ConfigController::getFormats` is a five-line read. The format filter on `BookController::index` (`whereHas('versions.format', fn ($q) => $q->where('formats.name', $format))`) is the entry point for the `/formats/:slug` browse view. There is also a dead `BookController::getBooksByFormat` method that is *not* registered in `routes/api.php` — see Non-obvious decisions below.
- **Services**: none. Format logic is inline in the two controllers above and in `BookController::prepareVersions` (which special-cases format `'Audiobook'` / `'Paper'` to decide whether `audio_runtime` is kept or nulled).
- **Models**: `Format` (`format_id` PK, fillable `['name', 'slug']`) declares both `versions(): HasMany` and a convenience `books(): BelongsToMany` that traverses through the `versions` table (`belongsToMany(Book, 'versions', 'format_id', 'book_id')`). `Version::format(): BelongsTo` is the inverse and is the load shape every consumer expects (`versions.format`).
- **Policies / authorization**: none. `POST /formats` is gated only by `auth:sanctum` — there is no admin role, no `FormatPolicy`, no gate. Anyone with a valid token can create a format.
- **Migrations**: `2023_09_09_000004_create_formats_table.php` defines just `format_id` / `name` / nullable `slug` / timestamps. `name` is **not** unique at the DB level (the controller-side `unique:formats,name` rule is the only dedupe). `slug` is nullable, not unique, and not indexed. The `versions` table (`2023_09_09_000005_create_versions_table.php`) holds the `format_id` FK that joins back here.

### Frontend

- **API layer**: there is no dedicated `FormatController.js` wrapper. `ConfigStore` calls `makeRequest`/`buildUrl` directly against `config/formats` and `formats`. The "browse books by format" call lives on `api/BookController.js::getBooksByFormat`, which is just `GET /books` with `{ page, format }` passed through as query params — it is *not* a separate format endpoint.
- **Stores**: `ConfigStore` (`stores/ConfigStore.js`) holds `state.books.formats` (the cached `[{format_id, name}]` list) and exposes `checkForFormats()` (load-once-if-empty), `setFormats()` (force refresh), and `createFormat(name)` (POST + push the response onto the cached list). There is no `currentFormat` and no per-format book cache; the browse view stores its results on `BooksStore.allBooks`.
- **Service**: none.
- **Routes**: `/formats/:format` (`formats.show`) is registered directly in `router/index.js` (no `format-routes.js` file). The param is the format slug. The admin route `/admin/formats` lives in `router/admin-routes.js` and dispatches to `FormatsIndex` via the `meta.component` indirection on `AdminActionView`.
- **Views**: `views/FormatView.vue` (browse books for one format) and `views/admin/AdminHome.vue` (single link to `admin.formats`). There is no `views/admin/FormatsView.vue` — the admin route renders `views/admin/AdminActionView.vue`, which mounts the `FormatsIndex` *component* by name.
- **Components**: `components/admin/FormatsIndex.vue` (wraps the list + create form in a `<Suspense>` boundary), `components/admin/FormatsList.vue` (top-level `await configStore.checkForFormats()` — relies on the parent Suspense), `components/admin/CreateFormat.vue` (single-input form that calls `ConfigStore.createFormat`). The format `<select>` on book / version forms is duplicated across `components/newBook/NewVersionsInput.vue` and `components/books/forms/BookCreateEditForm.vue`; both read from `ConfigStore.books.formats` and call `ConfigStore.checkForFormats()` on mount.

## Non-obvious decisions and gotchas

- **`format_id` custom PK and "through-versions" `books()` relation.** `Format::$primaryKey = 'format_id'`, and `Format::books()` is a `belongsToMany` that uses the `versions` table as a pivot. A book that has multiple versions in the same format will appear *multiple times* if you ever call `Format::find($id)->books` directly — the relation does not `distinct()`. No code does this today (the browse path goes through `Book::whereHas('versions.format', ...)` instead), but it is a footgun for future contributors.
- **`/api/config/formats` lives under `/config/` for a reason.** Formats are bootstrap data the SPA needs before any book form can render. The `ConfigController` namespace is the seam for additional bootstrap data (page-size defaults, feature flags, etc.); putting formats under `/formats` would imply CRUD that doesn't exist there. The shape is intentionally minimal — `[{format_id, name}]` — even though the underlying row also has `slug` and timestamps. New consumers that need the slug have to re-fetch from elsewhere or extend the projection.
- **Two payload shapes for the same row.** `GET /config/formats` returns `{format_id, name}` only. `POST /formats` returns the full Eloquent row including `slug`, `created_at`, `updated_at`. `ConfigStore.createFormat` pushes the latter onto the same array that `setFormats` populates with the former, so the cached list contains heterogeneous entries during a session that creates a format. No consumer reads anything beyond `format_id` and `name`, so it works — but it is a real shape inconsistency.
- **`POST /formats` derives the slug; nothing else does.** This is the only code path that writes a `slug`. Existing rows from earlier seeding may have `slug = null`; the `formats.show` route depends on slug being populated and routable. There is no backfill, no migration to make `slug` non-null, and no validation that the derived slug is unique (only `name` is `unique:formats,name`). Two formats whose names slug-collide ("Sci Fi" / "sci-fi") would produce duplicate slugs and break the browse route silently.
- **Browse-by-format filter compares against `name`, not `slug`.** `BookController::index` does `where('formats.name', $format)`. The SPA route param (`/formats/:format`) is a slug — `BookTableRow` builds the link as `params: { format: bookFormat.slug }`, and `FormatView` forwards `$route.params.format` straight into `getBooksByFormat`. This works today only because `Str::slug()` of a single-word name like "Audiobook" lowercases to "audiobook" but the WHERE compares case-insensitively under the default MySQL collation — i.e. it matches by accident for single-word formats, and will silently return zero books for any format whose name and slug differ (e.g. "Graphic Novel" → slug "graphic-novel" vs name "Graphic Novel"). See `/feature-plans/formats.md` for the fix.
- **Dead `BookController::getBooksByFormat` method.** A second implementation lives at `BookController::getBooksByFormat` that *does* look up by slug correctly (`Format::where('slug', $formatName)->first()`), but it is not registered in `routes/api.php` and includes a `Log::info(... ->toSql())` debug line. It is the abandoned right answer to the bug above.
- **Hardcoded format-name string-matching in two places.** `BookController::prepareVersions` branches on `$format->name == 'Audiobook'` / `$format->name == 'Paper'` to decide whether to keep `audio_runtime`. `NewVersionsInput.vue` checks `version.format?.name === 'Audiobook'` to show the audio-runtime field, but `validateVersion()` checks `version.format?.name === 'audio'` (lowercase, different string) — meaning the audio-runtime validation gate is dead code; the field shows up but is never required. `BookCreateEditForm.vue` uses a different convention again: `bookForm.versions[idx].format === 2` (hardcoded `format_id` of 2 for Audiobook). Three different ways to identify the same format.
- **`POST /formats` is not admin-gated.** The route sits inside the `auth:sanctum` group with no policy, no admin-role check, no gate. The "admin" surface is admin only by URL convention — `/admin/formats` in the router is not protected by anything. Any authenticated user can create a format via the API or the admin UI.
- **`FormatsList.vue` uses top-level `await` and depends on its parent `<Suspense>`.** `FormatsIndex` wraps it; mounting `FormatsList` outside a `<Suspense>` boundary will warn and break. New admin lists that copy this pattern need to bring the Suspense wrapper with them.
- **`FormatsList.vue` keys on `format.id` but the field is `format_id`.** Cosmetic — Vue falls back to index-based keying with a warning. Will start mattering if the list ever supports reordering or deletion.
- **`createFormat` propagates errors; `setFormats` swallows them.** `ConfigStore.setFormats` catches and `console.log`s on failure, leaving `books.formats` empty (the form `<select>` will silently show only the disabled placeholder). `createFormat` rethrows so `CreateFormat.vue` can render the API error message. The two error-handling stances are inconsistent.
- **`AdminActionView` dispatches by `meta.component` string.** The `meta: { component: "FormatsIndex" }` route meta plus the local `components` map in `AdminActionView` is the extensibility seam for future admin actions — register the component in the map, point a route at `AdminActionView` with the string name in meta. Don't add new admin route components that bypass it.

## Usage notes

### Bootstrapping the formats list (SPA)

`GET /config/formats` returns:

```
[
  { format_id, name },
  ...
]
```

Called once per session by `ConfigStore.checkForFormats()`. The `<select>` controls in `NewVersionsInput.vue` and `BookCreateEditForm.vue` both call `checkForFormats()` on mount and read `ConfigStore.books.formats`. There is no invalidation: a format created via the admin UI in the same session is appended to the cached list by `createFormat`, but a format created in a *different* session won't be visible until the page is reloaded.

### Creating a format (admin)

`POST /formats` body: `{ "name": "Graphic Novel" }`. Returns 201 with the full row:

```
{ format_id, name, slug, created_at, updated_at }
```

Validation: `name` is required, ≤255 chars, and must be unique against `formats.name`. The slug is derived server-side via `Str::slug($name)` — the caller does not supply it. There is no PATCH or DELETE endpoint; format edits and deletes are not supported.

### Browsing books by format

The SPA links into `/formats/:slug` from any `BookTableRow` whose primary version has a format. The view calls `GET /books?format=<slug>` (via `getBooksByFormat`, which is just the books index with a query param). Returns the same shape as `GET /books` (paginated `{ books, pagination }`). Note the slug-vs-name caveat in the gotchas section: today this only matches reliably for single-word format names.

### Selecting a format in book / version forms

The format `<select>` is bound to either the full format object (new-book wizard, `NewVersionsInput`) or the `format_id` integer (`BookCreateEditForm`). Backend handlers normalize: `BookController::prepareVersions` reads `$version_data['format']` as a `format_id`, while `NewBookController::handleVersions` and `VersionController::addNewVersion` expect a `version.format.format_id` shape. The two handlers are not interchangeable.

## Related

- Plan file: `/feature-plans/formats.md` — future improvements and known limitations.
- `/documentation/books.md` — version creation, the `prepareVersions` audio-runtime branching, and the `Book::formats()` through-versions convenience relation.
- `/documentation/authors.md`, `/documentation/genres.md` — sibling taxonomy docs. Formats differ in that they are *not* created as a side effect of book creation, *do* have a derived slug on the create path, and *are* admin-managed (loosely) rather than user-supplied.
- `/feature-plans/documentation-backfill.md` — the backfill plan that schedules this doc; the admin surface (tier 4 admin.md) will cover the `meta.component` dispatch pattern in more depth once a second admin action exists.
