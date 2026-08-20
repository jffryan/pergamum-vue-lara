---
path: /feature-plans/
status: draft
---

# Locations (physical shelving)

## Goal

Give a physical copy a *place*, as a first-class concept, instead of encoding it as
membership in a `BookList`.

Today all 16 lists in the database are shelves — `O1S1`…`O1S5`, `O5S1`…`O5S4`,
`O6S1`, `H1S1`…`H1S3`, `B1S1`…`B1S3` — and all 465 versions sit on exactly one of
them. Lists are doing shelving duty exclusively, which means the moment a genuinely
curated list ships ("TBR", "best of 2026") the lists index becomes a mix of two
unrelated things.

Clutter is the visible symptom; the structural argument is that **location is a
different shape from every other grouping in the app**:

| | multiplicity | attaches to | ordered | owner |
|---|---|---|---|---|
| Genre | many per book | book (the work) | no | shared catalog |
| Tag (planned) | many per book | book (the work) | no | shared catalog |
| List | many per item | version | yes | user |
| **Location** | **exactly one** | **version (the copy)** | yes (left-to-right) | the physical world |

A book belongs to many genres and many lists. A physical copy is in one place.
`list_items` cannot express that — its unique index is `(list_id, version_id)`, so
nothing prevents one version sitting on two shelf-lists, and no constraint on a
membership table ever could. A nullable `versions.location_id` makes exclusivity a
database fact.

Locations also want a **hierarchy** that lists have no concept of: shelves roll up
into bookcases, bookcases into rooms. "What's in the office" is currently a
client-side union of five list payloads.

### Version means copy

Settled, and the data agrees: every book in the system is a real physical object,
and where two identical copies exist they are two `Version` rows. `The Children`
(book 439) is the only such case — `v446` (`nickname` NULL) on `O1S4` and `v447`
(`nickname` `'duplicate'`) on `O1S5`. So a single `location_id` per version is
correct and no `copies` table is needed. See "Nickname is the copy discriminator"
under Open questions for the one sharp edge this leaves.

`versions` is also already where physical-copy facts live: `is_discarded` /
`discarded_at` are properties of the object, not the edition. `location_id` belongs
next to them.

## Approach

### Schema

One self-referencing table rather than `rooms` + `bookcases` + `shelves`:

```
locations
  location_id   PK
  parent_id     FK -> locations.location_id, nullable, cascadeOnDelete? (see Open questions)
  code          string, the stable machine identity — 'O1S5', 'O1', 'O'
  name          string, nullable — human label, 'Office — tall case, middle shelf'
  kind          enum/string — 'room' | 'bookcase' | 'shelf' (open vocabulary)
  slug          string, derived from code
  ordinal       unsigned int, nullable — position among siblings
  timestamps
  unique (parent_id, code)
  unique (slug)
  index (parent_id)
```

```
versions
  + location_id   FK -> locations.location_id, nullable, nullOnDelete
  + shelf_ordinal unsigned int, nullable — left-to-right position on the shelf
  index (location_id)
```

Self-referencing rather than three tables because the depth is a convention, not a
schema: a box in the attic, a shelf-within-a-shelf, "lent to Dave", "Kindle" all
land as rows with a different `kind`, not as new tables. Depth is 3 today, so a
recursive walk is one or two extra queries — no closure table, no `ltree`.

`code` is separate from `name` so a location can be renamed without moving its
identity in the CSV round-trip (below) or its URL. `code` is what the importer
matches; `name` is what the UI prefers when set.

### Backfill

The migration is unusually safe — verified against live data:

- 16 lists, every one a shelf matching `^([A-Z])(\d+)S(\d+)$`.
- 465 versions, 465 distinct versions on lists → **100% coverage, zero unshelved**.
- **0 versions on more than one list** → no conflict rule needed.
- 0 discarded versions, so no "discarded but shelved" ambiguity to resolve.
- 0 groups of versions sharing `(book_id, format_id, nickname)` → no collapse risk.

Steps:

1. Create `locations` rows by parsing each list name: letter → `room` (created once
   per distinct letter, `code` = the letter, `name` left null for the operator to
   fill in), letter+number → `bookcase` under it, full code → `shelf` under that.
   Yields 3 rooms, 5 bookcases, 16 shelves.
2. For each `list_item`, set `versions.location_id` to the shelf and
   `shelf_ordinal` to the item's `ordinal`.
3. Assert 465 non-null `location_id` before committing; abort and report otherwise.
4. **Do not drop `lists` / `list_items`.** Leave the shelf lists in place behind the
   migration so the old data is recoverable, and delete them in a follow-up once
   the location UI is trusted. A second migration, or a manual step, not this one.

The migration should refuse and name offenders rather than guess, in the style of
`2026_08_09_000000_add_unique_index_to_genres_name`.

### Backend

- **Model**: `Location` (PK `location_id`). `parent()` / `children()`
  self-relations, `versions()` hasMany, plus a `descendants()` / `subtree()` helper
  and a `books()` reach-through for rollup. Scopes: `kind('shelf')`, `roots()`.
- **Routes** (`routes/api.php`, under `auth:sanctum`) — mirror the genres shape:
  - `Route::resource('/locations', LocationController::class)`
  - `GET /locations/{location}/books` — paginated listing, subtree-inclusive
- **Listing query**: build on `App\Support\BookListing::query()`, exactly as
  `GenreController::show` now does. Do not grow a third variant — the whole point of
  that class. A bookcase listing is the same query with `whereIn('location_id',
  $subtreeIds)`.
- **Service**: `LocationService` for create/rename/move/merge and the subtree walk.
  Moving a subtree (re-parenting a bookcase into another room) is the one operation
  with a cycle risk — reject a move into own descendant.
- **Requests**: `StoreLocationRequest`, `UpdateLocationRequest`,
  `MoveVersionRequest` (shelving a copy), all extending `ApiFormRequest`.
- **Authorization**: locations are shared catalog, like genres and formats — not
  per-user like lists. That follows the shared-catalog decision (CHANGELOG 0.1.7)
  and `/documentation/books.md`. No policy initially; if `/feature-plans/admin.md`
  items 1–2 land, locations join genres behind the same gate.
- **Statistics**: add `Scope::SHELF` and `Scope::BOOKCASE` (or one
  `Scope::LOCATION` whose subtree width does the work — probably better) as cases in
  `ScopeResolver::resolve`, plus `supports()` on the metrics that make sense.
  `TotalPages`, `CompletedCount`, `CompletedPercent`, `GenreBreakdown`,
  `AverageRating`, `RatingDistribution` all read naturally per shelf. The registry
  comment already advertises this as the extension point — no route churn, no new
  controller. `app/Statistics/Support/ListQuery.php` is the model for a
  `LocationQuery`.

### Frontend

- `api/LocationController.js` — thin wrapper on `makeRequest`/`buildUrl`, same shape
  as `GenreController.js`. The nested `/books` path is hand-built, as in
  `ListController.js`.
- `stores/LocationsStore.js` — the tree (fetched once, it's ~24 rows) plus
  `currentLocation`. Learn from `GenreStore`: **every mutation refetches**, because
  a move changes counts on rows the mutation never named.
- `router/location-routes.js` — `locations.index` (the tree),
  `locations.show` (a shelf or bookcase, slug-routed — not by ID; genres' plan item
  2 exists because IDs were chosen there), `locations.statistics`.
- Views: `LocationsView` (tree/browse), `LocationView` (a `BookshelfTable` of the
  subtree + child locations), `LocationStatisticsView` (reuse `StatisticsGrid`).
- Book detail gains a **"where is it"** line per version, linking to the shelf.
- Book edit / new-book gains a shelf picker per version.

### Bulk upload and database reset

This is the part most likely to be missed, and it is load-bearing.

`documentation/database-reset.md` names the `lists` CSV column as what makes shelf
membership survive a reset. If locations ship without a CSV equivalent, **a reset
silently loses the entire physical layout of the library.**

- Add a `location` column: the shelf `code` (`O1S5`). One value per row, because a
  copy has one place. A code with no matching location fails the row
  (`location_not_found`) — unlike lists, locations should **not** be
  find-or-created from an import, because a typo would silently invent a shelf.
- `BulkImportService::resolveVersion` sets `location_id` on **create only**, same
  rule as `is_discarded` — a re-import must not reshelve a copy you moved after
  writing the file.
- The export/reset path must emit `location` alongside `version_nickname`.
- Keep the `lists` column. It stops being how shelves round-trip and becomes what
  it says it is.

`/feature-plans/lists.md` flags bulk upload as a "second writer" for lists; locations
inherit the same warning, so record it in both places.

## Touches existing systems

- **`lists` / `list_items`** — the source of the backfill; not dropped by this plan.
- **`versions`** — new columns beside `is_discarded` / `discarded_at`.
- **`BulkImportService`** (`resolveVersion`, header validation, reason codes) and
  `/documentation/bulk-upload.md`'s column table.
- **`/documentation/database-reset.md`** — the round-trip table at line 39.
- **`App\Support\BookListing`** — third consumer. Confirm the sort whitelist covers
  what a shelf listing wants (a `shelf_ordinal` sort is new).
- **`app/Statistics/`** — `Scope`, `ScopeResolver`, per-metric `supports()`,
  and a `LocationQuery` beside `ListQuery`.
- **`/feature-plans/lists.md`** — item 9 (list-type registry) stops being speculative
  once shelves leave, because the remaining lists are genuinely one type and the
  next one added is genuinely different. Worth a cross-reference both ways.
- **`/feature-plans/statistics-widgets.md`** — "Author / genre / format scopes" gains
  a location sibling.
- **Discard flow** — decide whether discarding a copy clears `location_id`. It
  should: a copy you no longer own is not on a shelf. That is a `BookController`
  touch, not just a migration.

## Open questions

- **Should a third taxonomy (tags) exist at all?** Genre and tag are the same shape
  — many-per-book, unordered, no payload — so two tables means two pivots, two merge
  tools, two autocompletes, and a permanent argument about which bucket "World War
  One" goes in. The real difference is navigational: genres are a small browsable
  vocabulary (~140, the sqrt-scaled bars in `GenresView`), tags are an open long tail
  you filter by and never browse as a grid. That argues for a `kind` discriminator on
  `genres` rather than a `tags` table, making promotion an `UPDATE` instead of a data
  migration. **Out of scope for this plan** — noted here because it is the other half
  of the "three ways to group books" question that prompted it. Own it in
  `/feature-plans/genres.md` (which already has item 12, hierarchy/aliases, in the
  same neighbourhood).
- **Nickname is the copy discriminator, and nothing enforces it.**
  `resolveVersion` dedupes on `(book_id, format_id, nickname)`, so two identical
  copies with NULL nicknames on two different shelves would collapse to one version
  on import — losing a shelf assignment. Today this is safe (`The Children` uses
  `'duplicate'`, and there are zero colliding groups), but nothing stops the next
  duplicate from being created nickname-less through the book form. Options: validate
  at create time, auto-assign a nickname (`copy 2`), or fail the import row when a
  `location` is present and the version tuple is ambiguous. Decide before the CSV
  column ships.
- **What do `O`, `H`, `B` stand for?** The backfill creates rooms with `code` = the
  letter and `name` = null; someone fills in labels afterwards. Fine, but if the
  letters are meaningful the migration could seed them.
- **One `location` scope or two (`shelf` / `bookcase`)?** One scope whose subtree
  width varies is less code and means a room gets statistics for free. Two makes
  `supports()` declarations more legible. Leaning one.
- **`parent_id` delete behavior.** `cascadeOnDelete` would let deleting a room
  vaporize 5 bookcases and 16 shelves, orphaning 465 books' locations in one click —
  the same class of footgun as the lists cascade already flagged in
  `/feature-plans/lists.md`. Prefer `restrictOnDelete` plus an explicit "move
  children first" step, or refuse deletion of a non-empty location the way genre
  delete refuses without `?force=true`.
- **Is `shelf_ordinal` worth it now?** Physical left-to-right order is real and the
  data has it (the list `ordinal` column), so the backfill should carry it. But no UI
  needs it on day one, and lists' own plan notes their reorder endpoint shipped long
  before any drag UI. Carry the data, skip the reorder endpoint.
- **Capacity.** A shelf has finite width. A `capacity` column enables "this shelf is
  full" and a shelving-suggestion feature, but the unit is unclear (books? cm?).
  Deferred; noted because it is the first thing that will be asked for once shelves
  are browsable.

## What falls out once this exists

Not scope commitments — the point is that the model makes these cheap where lists
made them impossible.

1. **"Where is my copy?"** on the book detail page. Currently unanswerable without
   opening lists one by one.
2. **Bookcase and room rollup views** — a parent walk, not a client-side union.
3. **Statistics per shelf / case / room** — % read, pages, genre breakdown. Nearly
   free given the metric registry.
4. **Unshelved as a query** (`location_id IS NULL`), which is the "has the whole
   library been ported in?" job the lists were doing, expressed as a filter instead
   of a curation chore. Currently 0 rows — that is the invariant worth watching.
5. **Reshelving is a single FK update**, versus lists' all-or-nothing reorder.
6. **Shelf-audit mode** — walk a shelf with a phone, tick off what's physically
   there, surface the diff.
7. **Lists become intentional.** Every remaining list is a real curation decision,
   which is the precondition for the list-type registry.
