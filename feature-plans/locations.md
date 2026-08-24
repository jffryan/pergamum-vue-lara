---
path: /feature-plans/
status: living
---

# Locations (physical shelving)

Shipped 2026-08-22 (CHANGELOG 0.1.17); the descriptive content lives in
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

## Known limitations

- **Book create/edit forms don't carry a shelf picker.** Deliberate:
  shelving has one write path (`PATCH /versions/{version}/location`, used by
  the picker on the book page's version rows) rather than becoming a fifth
  field the three book-payload doors must keep agreeing on. A copy created
  through a form lands unshelved and is placed from the book page. Revisit
  only with a shape that keeps `LocationService::shelveVersion` the sole
  owner.
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
  undo that restores the old shelf — restore comes back unshelved by design.
