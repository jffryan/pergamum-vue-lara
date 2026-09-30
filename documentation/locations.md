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
- **Two virtual locations**, `unshelved` and `discarded`, are places a copy
  can be that are *not* rows: the holding pen for copies with no
  `location_id` (and not discarded), and the pile for `is_discarded` copies.
  Both are derived from the version's own columns, so a copy is always in
  exactly one of shelf / pen / pile and nothing has to keep a second fact in
  step. See "Virtual locations" below.

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
  `shelf_ordinal` (a copy you no longer own is not on a shelf — it is in the
  virtual `discarded` location by definition); restore does **not** reshelve,
  so a restored copy lands in `unshelved`. The reverse door is shut too:
  `LocationService::shelveVersion` throws `CopyDiscardedException` (422,
  `copy_discarded`) when asked to shelve a discarded copy, and the importer
  fails a row that is both discarded and located
  (`location_on_discarded_copy`).
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

### Virtual locations

- **Registry**: `App\Support\VirtualLocations` (the list, `find(slug)`,
  `isReserved(slug)`, `routePattern()`, `toIndexRows()`) over
  `App\Support\VirtualLocation` value objects — `slug`, `code`, `name`,
  `description`, and a `constrain(Builder)` closure that narrows a `versions`
  query to the copies held there (`whereNull(location_id) AND NOT
  is_discarded` for the pen; `is_discarded` for the pile). A third virtual
  place is one entry plus the column it derives from.
- **Routes**: `GET /locations/{virtual}` → `LocationController::showVirtual`
  and `GET /locations/{virtual}/books` → `virtualBooks`, declared *before*
  the slug-bound resource routes and pinned with `->where('virtual',
  VirtualLocations::routePattern())`. Same payload shapes as `show` /
  `books`: the location carries `location_id: null`, `kind: 'virtual'`,
  `virtual: true` and a `description`; `ancestors` and `children` are empty;
  the listing is the same one-row-per-copy flatten (`copiesListing` /
  `copiesResponse`, shared with the real `books`), sorted by the standard
  `BookListing` keys since nothing here has a shelf order.
- **Index**: `GET /locations` appends `VirtualLocations::toIndexRows()` —
  the two virtual entries with live `versions_count` — after the real rows.
- **Reserved slugs**: `LocationService::create`, `update` (on a code change)
  and `findOrCreateByCode` throw `LocationCodeReservedException` (422,
  `location_code_reserved`) when the code slugs to a virtual slug; the
  importer fails such a row up front with `location_reserved`, even under
  `create_locations`. Without this the virtual routes would silently shadow
  a real row.
- **Statistics** do not resolve virtual slugs (`Scope::$model` is a
  `Model`); the SPA hides the link on those pages.

### Frontend

- **API**: `api/LocationController.js` (slug-keyed, plus
  `setVersionLocation`).
- **Store**: `stores/LocationsStore.js` — the flat tree fetched once,
  hierarchy derived by getters (`roots`, `childrenOf`, `bySlug`, `leaves` —
  leaves being the shelvable targets), `currentLocation` for the show
  payload. `setAllLocations` splits the index payload on the `virtual` flag:
  real rows into `allLocations` (so the tree getters, `ShelfPicker` and the
  admin tree never see a virtual entry), virtual ones into
  `virtualLocations` (`virtualBySlug`). Every mutation refetches,
  GenreStore-style, because `versions_count` changes on rows a move never
  named.
- **Routes**: `router/location-routes.js` — `locations.index`,
  `locations.show` (`/locations/:slug`), `locations.statistics`.
- **Views**: `LocationsView` (room → bookcase → shelf browse with recursive
  subtree counts, then a "Not on a shelf" section listing the virtual
  locations with their counts and descriptions; it force-refetches the
  index on mount since the page *is* the counts), `LocationView`
  (breadcrumb, child chips, paginated `BookshelfTable` of the subtree with
  `per-copy` set — rows key on the copy and `BookTableRow` shows its
  nickname under the title, which is what tells two copies of one book
  apart), `LocationStatisticsView`
  (`StatisticsGrid` + the `locationStatistics` surface config). On leaf
  locations, `LocationView` also renders
  `components/books/AddBookSearch.vue` — the list page's title-search /
  pick-a-version widget, extracted to a shared component — so copies can be
  shelved from the shelf page; the view's `addVersion` calls the same
  `setVersionLocation` write path, then quietly refetches the listing.
  The same view serves `/locations/unshelved` and `/locations/discarded`:
  on a `virtual` payload it drops the kind, the Statistics link and
  `AddBookSearch`, shows the description, and gives each row the one
  transition out — `ROW_ACTIONS` maps slug → `shelve` (a per-row
  `ShelfPicker`, through `setVersionLocation`) or `restore` (through
  `restoreVersion`); either refetches the listing and force-refetches the
  index. `BookshelfTable` / `BookTableRow` grew an optional scoped
  `#actions="{ book }"` slot for this, rendered as a strip under the row
  and only when supplied, so other listings are unchanged.
- **New-book and add-a-copy pages**: a Shelf select in the shared
  `components/books/CopyFields.vue` (`LocationsStore.shelfOptions`, the
  same leaves-with-paths list `ShelfPicker` uses) sends `location_id` with
  the new copy; blank is unshelved.
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
  `PATCH /versions/{version}/location`, and every other door that places a
  copy goes through the same `LocationService::shelveVersion` rather than
  writing the column. Two form doors do so: the new-book page
  (`POST /create-book`, an optional `location_id` per *new* copy) and the
  add-a-copy page (`POST /versions`, an optional `location_id`), each
  shelving inside its create transaction — see
  `/documentation/new-book-creation.md` and `/documentation/books.md`. The
  edit form still doesn't carry a location; moving an existing copy is the
  picker's job. The importer is the known exception (see the plan's second
  writer warning).
- **Nickname is the copy discriminator.** The importer's version dedupe
  tuple `(book, format, nickname)` is also the copy identity, so a file
  putting the same tuple in two places fails the later row
  (`ambiguous_copy`) rather than silently collapsing two copies.
- **Deleting structure is never implicit.** The FK is `restrict`, the
  service refuses a parent with children even under `force`, and `force` on
  a leaf means "unshelve these copies", nothing more.
- **Unshelved and Discarded are derived, not stored.** A `locations` row for
  either would be a second fact to keep in step with `location_id` /
  `is_discarded` across every write door (picker, discard endpoint, forced
  delete, form-created versions, the CSV importer) — the "second writer"
  problem the plan already warns about, doubled. Deriving them means the
  sync the discard flow promises ("discarding removes a copy from its shelf
  and puts it in the pile") is true by construction; the only rule that
  needed adding was refusing to shelve a discarded copy.
- **`shelf_ordinal` round-trips but has no reorder UI** — the data was
  carried from `list_items.ordinal` and the CSV keeps it; a drag UI is a
  future improvement in the plan.
