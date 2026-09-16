---
path: /documentation/
status: living
---

# Locations (physical shelving)

## Summary

A location is a physical place a copy can be — shelf, bookcase, room, or
anything else — as a first-class concept. Every `Version` has at most one
`location_id`, replacing the old convention of encoding shelves as `BookList`s
(the 16 `O1S5`-style lists, which remain in the database as recoverable backup
but are no longer how shelving works).

## Domain shape

- **One self-referencing table**, `locations`: `location_id` PK, nullable
  `parent_id` FK (`restrict` on delete), `code` (stable machine identity,
  `'O1S5'` / `'O1'` / `'O'`), nullable `name` (human label, `'Office'`),
  `kind` (open string vocabulary — `room` / `bookcase` / `shelf` today),
  unique `slug` (lowercased code), nullable `ordinal` (position among
  siblings). Depth is a convention, not a schema: a box or a "lent out" pile
  is a row with a different `kind`.
- **`versions.location_id`** (nullable, `set null` on delete) plus
  **`versions.shelf_ordinal`** (left-to-right position). These sit beside
  `is_discarded` / `discarded_at` because all four are facts about the
  physical object, not the edition. Exclusivity — a copy is in exactly one
  place — is a database fact, which a membership table could never enforce.
- Codes are globally unique via the slug index; `code` and `name` are
  separate so renaming never moves a location's identity in URLs or the CSV.

## How it's wired

### Backend

- **Migrations**: `2026_08_22_000000_create_locations_table`,
  `…000001_add_location_to_versions_table`,
  `…000002_backfill_locations_from_shelf_lists` — the backfill parses
  `^([A-Z])(\d+)S(\d+)$` list names into the three-level tree (3 rooms, 5
  bookcases, 16 shelves from live data), points every listed version at its
  shelf, carries `list_items.ordinal` into `shelf_ordinal`, and refuses with
  named offenders if a version sits on two shelf lists. It does **not** drop
  the shelf lists.
- **Model**: `App\Models\Location` (PK `location_id`, routes by `slug`).
  `parent()` / `children()` / `versions()`, `subtreeIds()` and `ancestors()`
  (single-query edge-list walks — the table is ~24 rows), scopes `kind()` /
  `roots()`. `Version::location()` is the other side.
- **Service**: `App\Services\LocationService` — the single owner of code
  identity (`normalizeCode`, `findConflict`), tree integrity (`update()`
  guards moves against cycles), deletion semantics (`delete()` always refuses
  with children; refuses with shelved copies unless forced, and force
  unshelves them), `shelveVersion()` (the one write path for placing a copy),
  and `findOrCreateByCode()` (the `create_locations` import path only).
- **Exceptions** (`app/Services/Exceptions/`): `LocationCodeConflictException`
  (409, carries the colliding row), `LocationInUseException` (409, forceable),
  `LocationHasChildrenException` (409, not forceable),
  `LocationCycleException` (422).
- **Controller / routes** (`auth:sanctum`):
  `Route::apiResource('/locations')` + `GET /locations/{location}/books` +
  `PATCH /versions/{version}/location` (`MoveVersionRequest` — `location_id`
  must be present; `null` unshelves and clears `shelf_ordinal`). `{location}`
  is a slug. `index` returns the flat tree with `versions_count`; `show`
  returns `{location, ancestors, children, subtree_versions_count}`.
- **Books listing**: `LocationController::books` builds on
  `App\Support\BookListing` (the third consumer) filtered by
  `whereIn('versions.location_id', $subtreeIds)` — a bookcase or room page is
  the union of its subtree. Two departures from the library shape. A `shelf`
  sort (min `shelf_ordinal` among the book's copies in the subtree, nulls
  last), the default when the location's kind is `shelf`; it lives in the
  controller, not `BookListing::SORTABLE`, because it only means something
  inside one subtree. And **rows are copies, not books**: the `versions`
  eager load is overridden to only the subtree's copies (shelf order), and
  each paginated book is flattened to one row per copy carrying `versions:
  [that copy]`, so two copies of a novel on one shelf are two rows and the
  format/page count shown is the copy that is here — not the book's oldest
  version, which `BookListing`'s `versions[0]` convention would otherwise
  pick even when it is shelved elsewhere. Pagination stays per book
  (`pagination.total` counts books; `subtree_versions_count` on `show`
  counts copies), so a page holds at least `limit` rows.
- **Authorization**: `LocationPolicy`, all-true — the same admin-gate seam as
  `GenrePolicy`, for the same shared-catalog reason.
- **Discard flow**: `VersionController::discard` clears `location_id` and
  `shelf_ordinal` (a copy you no longer own is not on a shelf); restore does
  **not** reshelve.
- **Statistics**: `Scope::LOCATION`, resolved by slug (numeric id fallback)
  with no ownership gate. `Support\LocationQuery` mirrors `ListQuery` over
  `versions.location_id` in the subtree; `Support\ScopeQuery` dispatches the
  items-shaped metrics between list and location scopes. One scope serves
  shelf, bookcase and room — the subtree is just wider. Seven metrics
  support it: `totalItems`, `totalPages`, `completedCount`,
  `completedPercent`, `genreBreakdown`, `averageRating`,
  `ratingDistribution`.
- **CSV**: `location` column (`CODE` or `CODE|ordinal`) in
  `CsvContract::COLUMNS`; import sets it on version create only and never
  invents locations unless `create_locations` is sent; export emits it on
  every row of a shelved version. See `/documentation/bulk-upload.md` and
  `/documentation/database-reset.md`.

### Frontend

- **API**: `api/LocationController.js` (slug-keyed, plus
  `setVersionLocation`).
- **Store**: `stores/LocationsStore.js` — the flat tree fetched once,
  hierarchy derived by getters (`roots`, `childrenOf`, `bySlug`, `leaves` —
  leaves being the shelvable targets), `currentLocation` for the show
  payload. Every mutation refetches, GenreStore-style, because
  `versions_count` changes on rows a move never named.
- **Routes**: `router/location-routes.js` — `locations.index`,
  `locations.show` (`/locations/:slug`), `locations.statistics`.
- **Views**: `LocationsView` (room → bookcase → shelf browse with recursive
  subtree counts), `LocationView` (breadcrumb, child chips, paginated
  `BookshelfTable` of the subtree with `per-copy` set — rows key on the
  copy and `BookTableRow` shows its nickname under the title, which is what
  tells two copies of one book apart), `LocationStatisticsView`
  (`StatisticsGrid` + the `locationStatistics` surface config). On leaf
  locations, `LocationView` also renders
  `components/books/AddBookSearch.vue` — the list page's title-search /
  pick-a-version widget, extracted to a shared component — so copies can be
  shelved from the shelf page; the view's `addVersion` calls the same
  `setVersionLocation` write path, then quietly refetches the listing.
- **Book page**: the version table has a Location column — a link to the
  shelf, or "Unshelved" — and a Shelve/Move action opening
  `components/locations/ShelfPicker.vue` (a select over the tree's leaves,
  labelled with ancestor paths). The row emits; `BookView::moveCopy` calls
  the API and replaces the version, exactly like discard/restore.
- **Admin**: `/admin/locations` (`components/admin/locations/`) — the tree
  as recursive `LocationNode` rows with Edit (code/name/kind/ordinal via the
  shared `LocationForm`), Move (a parent select excluding the row's own
  subtree — the server's cycle guard, mirrored so the option list is honest),
  Add child, and a two-step Delete in the genre-row mold: the first confirm
  goes without `force` so the server reports what's in the way; shelved
  copies escalate to an acknowledged force (which unshelves), child locations
  are a hard stop. `LocationsStore.pathLabel` / `subtreeIdsOf` getters serve
  both this screen and `ShelfPicker`.
- **Nav**: `SidebarNav` links Locations under Genres.

## Non-obvious decisions

- **Shelving has one write path.** The picker calls
  `PATCH /versions/{version}/location`; the book create/edit payloads do
  *not* carry `location_id`. Threading it through the three book-form doors
  plus add-version would create four more ingest doors to keep agreeing —
  the exact drift the genre and author consolidations just cleaned up.
- **Nickname is the copy discriminator.** The importer's version dedupe
  tuple `(book, format, nickname)` is also the copy identity, so a file
  putting the same tuple in two places fails the later row
  (`ambiguous_copy`) rather than silently collapsing two copies.
- **Deleting structure is never implicit.** The FK is `restrict`, the
  service refuses a parent with children even under `force`, and `force` on
  a leaf means "unshelve these copies", nothing more.
- **`shelf_ordinal` round-trips but has no reorder UI** — the data was
  carried from `list_items.ordinal` and the CSV keeps it; a drag UI is a
  future improvement in the plan.
