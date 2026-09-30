---
path: /feature-plans/
status: living
---

# Locations (physical shelving)

Shipped 2026-08-22 (CHANGELOG 0.1.17); the virtual `unshelved` / `discarded`
locations followed 2026-09-17 (0.1.22). The descriptive content lives in
`/documentation/locations.md`. Decisions taken at implementation, for the
record: `restrict` + refuse-non-empty delete (force only unshelves a leaf's
copies), one `location` statistics scope rather than shelf/bookcase pairs,
`ambiguous_copy` import failure for the nickname-discriminator gap, and the
CSV `location` column carries `CODE|ordinal` (not the bare code the draft
sketched) so `shelf_ordinal` survives a reset. The reset gap the draft missed
— `migrate:fresh` empties `locations` and the importer refuses unknown codes
— is closed by the opt-in `create_locations` upload flag.

**Second writer warning (mirrors `/feature-plans/lists.md`):** bulk upload
writes `versions.location_id` / `shelf_ordinal` directly from
`BulkImportService`, bypassing `LocationService::shelveVersion`, and with
`create_locations` it creates `locations` rows via
`LocationService::findOrCreateByCode`. Any change to code identity, slug
derivation, or the shelf-code convention has to hold for both doors.

## Future improvements

1. **Delete the shelf lists.** The 16 `O1S5`-style lists still exist behind
   the backfill migration as recoverable backup. Once the location UI has
   been trusted through a few weeks of real use, delete them (a migration or
   a manual pass), at which point `/feature-plans/lists.md` item 9 (list-type
   registry) stops being speculative.
2. **Floated future rooms** from the draft: `D` = Downstairs, `Bo` = Boxed,
   `L` = Loose. `/admin/locations` (shipped 2026-08-22) creates them when
   they become real.
3. **Shelf reorder UI.** `shelf_ordinal` is carried, exported, imported, and
   drives the default shelf sort, but nothing edits it except the CSV and the
   shelve endpoint's optional parameter. Lists shipped reorder the same way
   (endpoint long before drag UI).
4. **`LocationService::merge`** (fold shelf A into shelf B) was planned but
   not built — no UI needed it. Reshelving each copy by hand or CSV covers
   the rare case; add it beside the genre merge if shelves start churning.
5. **Capacity.** A `capacity` column enables "this shelf is full" and
   shelving suggestions; unit still unclear (books? cm?). First thing that
   will be asked for now that shelves are browsable.
6. **Shelf-audit mode.** Walk a shelf with a phone, tick off what's
   physically there, surface the diff.
7. **Location names in the reset round-trip.** `create_locations` rebuilds
   the tree from codes, but `name`s (`'Office'`) aren't in the CSV and come
   back null. A tiny locations export (code, name, kind, parent, ordinal)
   would close it if re-entering three room names ever grates.
8. **Tags as a genre `kind`** — the other half of the "three ways to group
   books" question that prompted this plan. Owned by
   `/feature-plans/genres.md`; noted here only for the cross-reference.
9. **Statistics for the virtual locations.** `Scope::$model` is typed
   `?Model`, so `ScopeResolver::location` can't hand a `VirtualLocation` to
   `LocationQuery`. "What have I discarded, by genre" is the interesting one;
   loosening the scope's model type (or a `virtual_location` scope) and a
   `constrain()` arm in `LocationQuery::items` would do it. The SPA hides
   the link until then.
10. **Bulk actions on the pen.** The unshelved page places one copy at a
    time. Once real unshelved backlogs appear (a box of new arrivals), a
    "shelve all selected to …" over the listing would be the next ask —
    the per-row `#actions` slot on `BookshelfTable` is the seam.
11. **More virtual places** — "lent out", "boxed" — would each need a
    column to derive from (the whole point is that they are not rows), so
    they are a schema decision first. `VirtualLocations` is one entry per
    place once the column exists.

## Known limitations

- **The edit form doesn't carry a shelf picker.** Both copy-creating
  forms do (2026-09-29): the new-book page (`POST /create-book`) and the
  add-a-copy page (`POST /versions`) take an optional `location_id` and
  call `LocationService::shelveVersion`, so the service stays the sole
  owner. Moving an existing copy is the book page's picker, deliberately
  not a field on the edit form.
- **Nothing enforces the nickname-as-copy-discriminator rule at create
  time.** The importer fails ambiguous rows (`ambiguous_copy`), but the book
  form can still create a second nickname-less version of the same
  `(book, format)` — safe until that book's copies are shelved apart and
  then exported, at which point the export is un-reimportable without adding
  a nickname. Validate-at-create was considered and deferred.
- **`unique(parent_id, code)` doesn't bind two NULL-parent roots** (MySQL
  treats NULLs as distinct there); the unique slug is what actually stops two
  roots sharing a code.
- **Discarding silently unshelves.** Correct per the plan, but there is no
  undo that restores the old shelf — restore comes back in the unshelved
  pen by design, and is placed from there (or from the book page).
- **Shelving from the book page bypasses the store.** `BookView::moveCopy`
  calls `setVersionLocation` directly, so `LocationsStore.allLocations`
  counts go stale until something force-refetches. `LocationsView` now
  does on mount, which covers the page where it shows; the picker's own
  option list never carries counts, so nothing else notices.
