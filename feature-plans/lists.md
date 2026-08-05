---
path: /feature-plans/
status: living
---

# Lists

Tracks rough edges and follow-up work for the Lists domain (BookList / ListItem). Descriptive content lives in `/documentation/lists.md`.

**Second writer:** bulk upload creates `BookList`s and appends `ListItem`s directly from `BulkImportService`, bypassing `ListController` / `ListItemController`, by two routes: the file-level `list_name` option (via `App\Services\BulkImport\ListCollector`, which creates one brand-new list) and the per-row `lists` column (via `BulkImportService::fileIntoNamedLists`, which find-or-creates by slug and can carry an explicit ordinal). Both rely on the `(user_id, slug)` and `(list_id, version_id)` unique indexes and on `Str::slug($name)` matching `ListController::store`. Any change to list creation rules, slug derivation, or the item-append contract has to land in both — see `/documentation/bulk-upload.md`.

The `lists` column is also what makes lists survive a database reset; the ordinal rides in the CSV because row order can't carry it (a version on two lists sits at a different position in each). See `/documentation/database-reset.md`.

## Known limitations

### Authorization

- **No `ListItemPolicy`.** Item mutations all go through `$this->authorize('update', $list)`. This conflates "can the user edit the list's metadata" with "can the user add/remove items" — fine today, but as soon as a list grows a notion of collaborators or read-only sharing, the two will need to diverge and there's nowhere to put the rule.
- **`viewAny` returns `true` and `create` returns `true`** unconditionally. The actual scoping happens in `ListController::index` (`auth()->user()->lists()`) and `store` (`auth()->user()->lists()->create(...)`). It works, but the policy lies — a future refactor that adds, say, `Gate::authorize` checks elsewhere will pass when it should fail. Move the scoping intent into the policy.
- **`ListItemController::destroy` leaks the existence of foreign `list_item_id` values.** Route model binding loads `$item` without scoping it to `$list`, so when an authenticated user owns the target list but submits a `list_item_id` from someone else's list, `$this->authorize('update', $list)` passes (they own the target), the `$item->list_id !== $list->list_id` check fires, and the response is `404 {"message": "Item does not belong to this list."}`. A genuinely nonexistent ID returns a different 404 from route model binding. The two are distinguishable, which lets a logged-in user enumerate valid `list_item_id` values across all accounts. Fix: scope the binding (`Route::scopeBindings()` or implicit nesting via `->scopeBindings()` on the resource) so the item only resolves when it actually belongs to `$list`.

### Validation & request shape

- **`addItemToList` does not check for duplicates before inserting.** The `(list_id, version_id)` unique index will reject the second insert with a 500 (`SQLSTATE[23000]`). Catch it and return 409, or pre-check.

### Data integrity

- **Cascading delete is hard, no soft-delete.** Deleting a list immediately and irreversibly removes every `list_item` via DB cascade. There's no "undo" and no audit trail. Less catastrophic than the books cascade (no read history is lost — items only point at versions), but a misclick still vaporizes a curated reading list.
- **The slug column is generated but unused.** Every list write computes `Str::slug($name)` and writes it to a unique-indexed column, but no route or query reads it. Two lists with names that slugify identically (e.g. "Sci-Fi" and "Sci Fi") collide on insert and 500. The same trap fires from leading/trailing whitespace — `"  Want to Read  "` and `"Want to Read"` both slugify to `want-to-read`, so a paste with a stray newline 500s the second `store`/`update`. Either wire slugs into routing (and trim before slugifying) or drop the column.
- **`(list_id, version_id)` unique on items conflates "the same edition" with "duplicate."** A list can hold two distinct versions of the same book (paperback + audiobook), but not the same version twice — which is the right call for now, but means stats math has to dedupe by `book_id` everywhere it matters.
- **No max-items cap.** A list can grow unbounded, which interacts badly with the unpaginated `show` endpoint (below).

### Performance & query shape

- **`show` eager-loads the entire list with deep relations.** `items.version.book.authors`, `…book.genres`, `…book.readInstances` (filtered to the user), `…version.format` — fine for lists of dozens, expensive for hundreds. No pagination, no streaming. Stats view re-fetches the same payload.
- **`index` returns *every* list with a slim items projection,** but doesn't paginate either. A user with hundreds of lists pays an N+1-ish cost (one `lists` query + one `list_items` query per request) on every page load that hits the navbar.
- **`index` items omit `ordinal` from the projection** (`with('items:list_item_id,list_id,version_id')`). The relation's `orderBy('ordinal')` keeps array order correct, so the SPA still receives items in stored order, but each item carries no positional metadata. The moment a frontend wants to reason about position from the index payload (e.g. inline drag-reorder in the lists nav, or "position 5 of 12" badges), it has to derive it from array index — which breaks as soon as anything is filtered out client-side. Add `ordinal` to the projection.
- **`reorder` does N `UPDATE` statements inside the transaction,** one per item. For a 200-item reorder that's 200 round-trips. A single `CASE WHEN list_item_id = ? THEN ? … END` update would collapse it.
- **`reorder` takes no row-level lock on the list.** Two concurrent reorders against the same list interleave their per-item updates and produce a hybrid ordering that matches neither client's intent. `SELECT … FOR UPDATE` on the parent list (or a serializable transaction) inside the `DB::transaction` would close it. Low odds in practice — one user, one tab — but trivial to trigger from two browser tabs and silent when it happens.
- **`reorder` doesn't bump `lists.updated_at`.** Only the per-row `list_items.updated_at` advances, because the `update(['ordinal' => …])` call is on `ListItem`, not `BookList`. Anything that surfaces "list last modified" sees rename/add/remove but not reorder. Touch the parent at the end of the transaction (`$list->touch()`) if/when that field becomes visible.

### API surface

- **Reorder is all-or-nothing.** No "move item X from position 3 to position 7." Clients must send the full ordering even for a single swap. Fine for small lists; awkward for large ones over a slow connection.
- **No batch add.** Adding ten versions to a list is ten POSTs. The bulk-upload feature exists for books but has no list equivalent.
- **No "duplicate this list" or "merge two lists" endpoints.** Both are common asks for reading-list apps.
- **Item-add response shape differs from item-show shape.** `POST /lists/{id}/items` returns the new item with `version.book.authors` and `version.format` only; `GET /lists/{id}` returns items with the deeper graph (`book.genres`, `book.readInstances`). Frontend has to remember which fields are present in which context.

### Extensibility

- **No list "type."** Every list is a flat ordered collection. A "to-read" list, a "currently reading" list, a "favorites of 2026" list, and a "sci-fi recommendations" list are all the same shape today. CLAUDE.md flags exactly this case ("another list type") as the place to introduce a config-driven dispatch — the moment a second list-type ships (e.g. with item-level status: queued / reading / done), the policy / show shape / stats shape will all need to branch.
- **`ListsStore` doesn't model items.** Item add/remove/reorder mutate `this.list.items` locally in the view. If two views ever care about the same list's items simultaneously, they'll drift.

### Frontend

- **No drag-and-drop UI for `reorder`** despite the endpoint existing. The reorder API has been ready since the items table was built, but `ListItemsTable.vue` is read-only-with-a-remove-button.
- **`ListView.vue::searchForBook` paginates books at 20 per page** (the books index default) but only ever shows the first page. Searching a common word truncates results silently.
- **No optimistic updates** on add/remove. UI waits for the round trip; the "✓ Added" badge can lag visibly.
- **Rename and delete have no loading state.** Double-clicks on Save can fire two PATCHes; double-clicks on the delete confirm can fire two DELETEs (the second 404s, harmless but noisy in the console).

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Decide what to do with the slug column.** Either route by `/lists/{user}/{slug}` (and surface a slug-conflict UX) or drop the column and its unique index. Right now it's a 500-waiting-to-happen with no upside.
2. **Soft-delete lists** (`SoftDeletes` trait + `deleted_at`). The hard cascade-on-delete is the most user-hostile behavior in the lists flow.
3. **Optimistic updates in `ListsStore`** for add/remove/reorder. Move item-level state out of view-local `this.list.items` into the store at the same time, so two views can share it.
4. **Drag-and-drop reorder UI** in `ListItemsTable.vue`. The endpoint exists; this is purely frontend work (Vue Draggable or similar).
5. **Paginate the `show` endpoint's items** (and stream them into the store). Stats need the full set, but the list-detail view can render the first page eagerly and the rest lazily.
6. **Collapse `reorder` into a single SQL `CASE WHEN` update.** Or accept the per-item cost and document it.
7. **Catch the `(list_id, version_id)` unique violation in `addItemToList`** and return 409 instead of 500. Frontend can then disable the button rather than guessing from the visible items list.
8. **Add a "list type" enum and a `ListTypeHandler` registry** keyed by type slug, each defining what fields apply, what stats to compute, and what default ordering to use. This is the seam CLAUDE.md asks for. Don't build it until the second type is on the roadmap, but plan for it.
9. **Batch add endpoint** — `POST /lists/{id}/items/bulk` with `{ version_ids: [...] }`. Wraps the duplicate check and the ordinal assignment in one transaction.
10. **"Duplicate list" and "merge lists" endpoints**, once the type system above is in place.
11. **Normalize the item response shape** between `POST /lists/{id}/items` and the item objects inside `GET /lists/{id}`. One eager-load definition, used by both.
12. **Loading/disabled state on rename and delete** in `ListView.vue` to prevent double-submits.
